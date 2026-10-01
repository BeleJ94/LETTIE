<?php

declare(strict_types=1);

namespace Tools\Demo;

use App\Core\Container;
use App\Core\FileStorage;
use App\Core\Request;
use App\Core\TableRequest;
use App\Domain\Assignment\AssignmentRequest;
use App\Domain\Assignment\AssignmentRole;
use App\Domain\Correspondent\CorrespondentInput;
use App\Domain\Correspondent\CorrespondentType;
use App\Domain\Mail\Channel;
use App\Domain\Mail\Confidentiality;
use App\Domain\Mail\MailAction;
use App\Domain\Mail\MailFilter;
use App\Domain\Mail\Priority;
use App\Domain\Retention\RetentionAction;
use App\Domain\SiteScope;
use App\Repositories\DelegationRepository;
use App\Repositories\MailRepository;
use App\Services\CorrespondentService;
use App\Services\DelegationService;
use App\Services\DocumentService;
use App\Services\MailService;
use App\Services\RetentionService;
use App\Services\WorkflowService;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * Cases that random data would rarely produce, created on purpose at their date,
 * each with an automatic check run after generation (see Checks).
 */
final class EdgeCases
{
    /** @var array<string, int|null> case => main record id */
    public array $ids = [];
    /** @var array<string, Closure(): array{0: bool, 1: string}> */
    private array $expectations = [];
    /** @var list<string> */
    public array $skipped = [];

    private readonly DateTimeZone $zone;

    public const SPECIAL_SUBJECT = 'Réclamation « urgente » — l’œuvre d’Æsop & <script>alert(1)</script> 📬';

    public function __construct(
        private readonly Generator $g,
        private readonly Container $c,
        private readonly PDO $pdo,
        private readonly Organisation $org,
    ) {
        $this->zone = new DateTimeZone('Europe/Paris');
    }

    /** @return array<string, Closure(): array{0: bool, 1: string}> */
    public function expectations(): array
    {
        return $this->expectations;
    }

    private function at(DateTimeImmutable $now, int $daysAgo, string $time = '10:00'): DateTimeImmutable
    {
        [$h, $m] = array_map('intval', explode(':', $time));
        return $this->g->workMoment($now->setTimezone($this->zone)->modify("-{$daysAgo} days")->setTime($h, $m));
    }

    private function wf(): WorkflowService
    {
        return $this->c->get(WorkflowService::class);
    }

    private function value(string $sql, array $params = []): mixed
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    }

    /** Schedules every case inside the simulated window [from, now). */
    public function register(DateTimeImmutable $from, DateTimeImmutable $now): void
    {
        $windowDays = (int) $from->diff($now)->days;
        $sec = $this->org->secretariatOf('SIEGE');

        // Showcase for the user guide: rich history on a recent mail.
        $this->g->schedule($this->at($now, 6, '09:12'), function () use ($now, $sec): void {
            $mail = $this->g->incoming('SIEGE', [
                'subject' => 'Demande de subvention — rénovation de la salle polyvalente',
                'summary' => 'Dossier de demande de subvention au titre de la DETR 2027, accompagné du devis et du plan de financement.',
                'correspondent' => $this->org->correspondents['SIEGE'][1], 'department' => 'FIN', 'channel' => Channel::Registered,
                'priority' => Priority::High, 'confidentiality' => Confidentiality::Internal, 'external_reference' => 'CD69-2026-4471',
                'attachments' => [['Dossier DETR 2027.pdf', 'pdf']],
            ]);
            $this->ids['showcase'] = $mail->id;
            $this->wf()->assign($this->org->actor($sec), $mail->id, new AssignmentRequest($this->org->users['head'], null, AssignmentRole::ForAction, 'Vérifier l\'éligibilité et préparer l\'avis pour le bureau communautaire.'));
            $this->wf()->assign($this->org->actor($sec), $mail->id, new AssignmentRequest($this->org->users['management'], null, AssignmentRole::ForInformation));
            $this->g->schedule($this->at($now, 5, '10:40'), function () use ($mail): void {
                $this->g->act($mail->id, 'head', MailAction::Start);
                $this->wf()->annotate($this->org->actor('head'), $mail->id, 'Éligible DETR. Plan de financement à compléter par le service technique (devis n°2).', false);
            });
            $this->g->schedule($this->at($now, 4, '15:05'), fn () => $this->wf()->annotate($this->org->actor('management'), $mail->id, 'Priorité du bureau : réponse avant la fin du mois.', false));
        });
        $this->expectations['showcase'] = fn (): array => [($this->ids['showcase'] ?? 0) > 0, 'mail #' . ($this->ids['showcase'] ?? '?')];

        // Year change: the last mail of a year and the first of the next (numbering restarts at 00001).
        $newYear = new DateTimeImmutable(((int) $now->setTimezone($this->zone)->format('Y')) . '-01-01 00:10', $this->zone);
        if ($newYear->modify('-1 day') > $from && $newYear < $now) {
            $this->g->schedule($newYear->modify('-20 minutes'), function (): void {
                $this->ids['year_last'] = $this->g->incoming('SIEGE', ['subject' => 'Vœux du préfet — courrier du 31 décembre', 'attachments' => []])->id;
            });
            $this->g->schedule($newYear, function (): void {
                $this->ids['year_first'] = $this->g->incoming('SIEGE', ['subject' => 'Premier courrier de l\'année', 'attachments' => []])->id;
            });
            $this->expectations['year_change'] = function () use ($newYear): array {
                $first = (string) $this->value('SELECT reference FROM mails WHERE id = ?', [$this->ids['year_first'] ?? 0]);
                $last = (string) $this->value('SELECT reference FROM mails WHERE id = ?', [$this->ids['year_last'] ?? 0]);
                $year = $newYear->format('Y');
                return [$first === "ENT-{$year}-00001" && str_starts_with($last, 'ENT-' . ($year - 1) . '-'), "{$last} → {$first}"];
            };
        } else {
            $this->skipped[] = 'year_change (no 1 January in the window)';
        }

        // Secret mail: subject never in exported documents.
        $this->g->schedule($this->at($now, 3, '11:00'), function (): void {
            $mail = $this->g->incoming('SIEGE', ['subject' => 'Signalement confidentiel — ressources humaines', 'department' => 'RH',
                'confidentiality' => Confidentiality::Secret, 'priority' => Priority::Urgent, 'channel' => Channel::HandDelivered]);
            $this->ids['secret'] = $mail->id;
            $this->g->planProcessing($mail, 'SIEGE', 'forgotten');
        });
        $this->expectations['secret_masked_in_exports'] = function (): array {
            $export = $this->c->get(DocumentService::class)->listExport(new MailFilter(), TableRequest::fromRequest(new Request('GET', '/'), ['mail_date'], 'mail_date', 'desc'));
            $row = array_values(array_filter($export['data'], fn (array $r): bool => $r['id'] === ($this->ids['secret'] ?? 0)))[0] ?? null;
            return [$row !== null && $row['subject'] === null && $row['masked'] === true, $row === null ? 'not exported' : 'subject masked'];
        };

        // Outgoing mail has no due date.
        $this->g->schedule($this->at($now, 2, '14:30'), function (): void {
            $this->ids['no_due'] = $this->g->outgoing('SIEGE', ['subject' => 'Envoi du compte rendu du conseil communautaire', 'close' => false])->id;
        });
        $this->expectations['outgoing_without_due_date'] = fn (): array => [
            $this->value('SELECT due_date FROM mails WHERE id = ?', [$this->ids['no_due'] ?? 0]) === null, 'due_date NULL'];

        // A correspondent that later closed down (deactivated in finalize).
        $this->g->schedule($this->at($now, min($windowDays - 2, 40), '09:30'), function (): void {
            $closed = $this->c->get(CorrespondentService::class)->create($this->org->actor('admin'), new CorrespondentInput(
                CorrespondentType::Organization, 'Imprimerie Dumas', null, null, null, '2 rue des Ateliers', null, '69480', 'Anse', 'FR', 'Fournisseur historique.',
            ), $this->org->sites['SIEGE']);
            $this->ids['inactive_correspondent'] = $closed->id;
            $mail = $this->g->incoming('SIEGE', ['subject' => 'Facture de reprographie', 'correspondent' => $closed->id, 'department' => 'FIN']);
            $this->g->planProcessing($mail, 'SIEGE', 'close');
        });

        // Maximum lengths everywhere (layout, PDF, Excel must cope).
        $this->g->schedule($this->at($now, 4, '16:00'), function (): void {
            $name = mb_substr(str_repeat('Établissement public intercommunal de coopération culturelle ', 4), 0, 190);
            $correspondent = $this->c->get(CorrespondentService::class)->create($this->org->actor('admin'), new CorrespondentInput(
                CorrespondentType::Organization, $name, null, null, null, null, null, null, 'Saint-Germain-au-Mont-d\'Or', 'FR', null,
            ), $this->org->sites['SIEGE']);
            $subject = mb_substr(str_repeat('Demande d\'avis sur le projet de schéma de cohérence territoriale révisé ', 5), 0, 255);
            $mail = $this->g->incoming('SIEGE', [
                'subject' => $subject,
                'summary' => mb_substr(str_repeat('Le présent courrier expose en détail le contexte, les enjeux et le calendrier de la consultation. ', 60), 0, 5000),
                'correspondent' => $correspondent->id,
                'external_reference' => str_repeat('X', 100),
                'attachments' => [[mb_substr(str_repeat('Annexe technique détaillée ', 10), 0, 196) . '.pdf', 'pdf']],
            ]);
            $this->ids['long_texts'] = $mail->id;
            $this->wf()->annotate($this->org->actor('admin'), $mail->id, mb_substr(str_repeat('Observation détaillée. ', 300), 0, 5000), false);
        });
        $this->expectations['maximum_lengths_kept'] = fn (): array => [
            (int) $this->value('SELECT CHAR_LENGTH(subject) FROM mails WHERE id = ?', [$this->ids['long_texts'] ?? 0]) === 255
            && (int) $this->value('SELECT CHAR_LENGTH(summary) FROM mails WHERE id = ?', [$this->ids['long_texts'] ?? 0]) === 5000,
            'subject 255, summary 5000 characters'];

        // Special characters: French typography, HTML, emoji (utf8mb4).
        $this->g->schedule($this->at($now, 1, '09:45'), function (): void {
            $mail = $this->g->incoming('SIEGE', ['subject' => self::SPECIAL_SUBJECT, 'department' => 'JUR']);
            $this->ids['special_characters'] = $mail->id;
            $this->wf()->annotate($this->org->actor('admin'), $mail->id, 'Reçu ✅ — à traiter cette semaine 🙂', false);
        });
        $this->expectations['special_characters_round_trip'] = fn (): array => [
            $this->value('SELECT subject FROM mails WHERE id = ?', [$this->ids['special_characters'] ?? 0]) === self::SPECIAL_SUBJECT, 'stored byte for byte'];

        // Very overdue and never assigned: dispatchers must be reminded.
        $this->g->schedule($this->at($now, 50, '10:15'), function () use ($now): void {
            $mail = $this->g->incoming('SIEGE', [
                'subject' => 'Demande d\'indemnisation — dégât des eaux (en souffrance)', 'department' => 'JUR',
                'due' => $now->setTimezone($this->zone)->modify('-40 days')->format('Y-m-d'),
                'document_date' => $now->setTimezone($this->zone)->modify('-52 days')->format('Y-m-d'),
            ]);
            $this->ids['very_overdue_unassigned'] = $mail->id;
        });
        $this->expectations['very_overdue_reminds_dispatchers'] = fn (): array => [
            (int) $this->value("SELECT COUNT(*) FROM notifications WHERE type = 'overdue' AND mail_id = ?", [$this->ids['very_overdue_unassigned'] ?? 0]) > 0,
            'overdue notification sent to the secretariat'];

        // Image and TIFF attachments (TIFF is download-only).
        $this->g->schedule($this->at($now, 1, '15:20'), function (): void {
            $this->ids['image_attachments'] = $this->g->incoming('SIEGE', ['subject' => 'Photos du sinistre de la rue des Écoles', 'department' => 'TECH',
                'attachments' => [['Photo façade.png', 'png'], ['Plan cadastral.tif', 'tiff']]])->id;
        });
        $this->expectations['image_and_tiff_stored'] = fn (): array => [
            (int) $this->value("SELECT COUNT(DISTINCT mime_type) FROM attachments WHERE mail_id = ? AND mime_type IN ('image/png', 'image/tiff')", [$this->ids['image_attachments'] ?? 0]) === 2,
            'image/png + image/tiff'];

        // Reassignment and a private note.
        $this->g->schedule($this->at($now, 8, '09:00'), function () use ($now, $sec): void {
            $mail = $this->g->incoming('SIEGE', ['subject' => 'Demande de mutation interne', 'department' => 'RH', 'confidentiality' => Confidentiality::Confidential]);
            $this->ids['reassigned'] = $mail->id;
            $this->wf()->assign($this->org->actor($sec), $mail->id, new AssignmentRequest($this->org->users['agent'], null, AssignmentRole::ForAction));
            $this->g->schedule($this->at($now, 7, '11:00'), function () use ($mail, $sec): void {
                $this->wf()->reassign($this->org->actor($sec), $mail->id, new AssignmentRequest($this->org->users['agent5'], null, AssignmentRole::ForAction), 'Conflit d\'intérêts');
                $this->wf()->annotate($this->org->actor('agent5'), $mail->id, 'Note personnelle : rappeler l\'agent après l\'entretien.', true);
            });
        });
        $this->expectations['reassignment_and_private_note'] = fn (): array => [
            (int) $this->value("SELECT COUNT(*) FROM assignments WHERE mail_id = ? AND status = 'reassigned'", [$this->ids['reassigned'] ?? 0]) === 1
            && (int) $this->value('SELECT COUNT(*) FROM annotations WHERE mail_id = ? AND is_private = 1', [$this->ids['reassigned'] ?? 0]) === 1,
            '1 reassigned + 1 private note'];

        // Reply chain: the reply closes the incoming mail.
        $this->g->schedule($this->at($now, 9, '10:00'), function () use ($now): void {
            $mail = $this->g->incoming('SIEGE', ['subject' => 'Demande de rendez-vous avec le président', 'department' => 'DG']);
            $this->ids['replied'] = $mail->id;
            $this->g->schedule($this->at($now, 7, '16:00'), fn () => $this->g->reply($mail, 'SIEGE', $this->org->secretariatOf('SIEGE')));
        });
        $this->expectations['reply_closes_incoming'] = fn (): array => [
            $this->value('SELECT status FROM mails WHERE id = ?', [$this->ids['replied'] ?? 0]) === 'closed'
            && (int) $this->value("SELECT COUNT(*) FROM mail_links WHERE type = 'reply_to' AND target_mail_id = ?", [$this->ids['replied'] ?? 0]) === 1,
            'linked and closed'];

        // Second site: archived by its own (more specific) rules, files purged.
        if ($windowDays >= 230) {
            $this->g->schedule($this->at($now, $windowDays - 3, '10:00'), function (): void {
                $mail = $this->g->incoming('ANNEXE', ['subject' => 'Demande d\'acte de naissance', 'attachments' => [['Formulaire.pdf', 'pdf']]]);
                $this->ids['archived_purged'] = $mail->id;
                $this->g->schedule($this->g->workMoment($this->c->get(\App\Core\Clock::class)->now()->modify('+1 day')),
                    fn () => $this->g->act($mail->id, $this->org->secretariatOf('ANNEXE'), MailAction::Close));
            });
            $this->expectations['retention_archives_and_purges'] = function (): array {
                $id = $this->ids['archived_purged'] ?? 0;
                $path = (string) $this->value('SELECT stored_path FROM attachments WHERE mail_id = ?', [$id]);
                $purged = $this->value('SELECT purged_at FROM attachments WHERE mail_id = ?', [$id]) !== null;
                $fileGone = !$this->c->get(FileStorage::class)->exists($path);
                return [$this->value('SELECT status FROM mails WHERE id = ?', [$id]) === 'archived' && $purged && $fileGone, 'archived, file deleted, metadata kept'];
            };
        } else {
            $this->skipped[] = 'retention (window shorter than 230 days)';
        }

        // Site isolation: the annexe sees only its own mail.
        $this->expectations['second_site_isolated'] = function (): array {
            $annexe = $this->org->sites['ANNEXE'];
            $page = (new MailRepository($this->pdo, SiteScope::sites($annexe)))->page(new MailFilter(), TableRequest::fromRequest(new Request('GET', '/'), ['mail_date'], 'mail_date'));
            $expected = (int) $this->value('SELECT COUNT(*) FROM mails WHERE site_id = ?', [$annexe]);
            return [$page->total === $expected && $expected > 0, "{$expected} mails visible to the annexe only"];
        };
    }

    /** Things that happen "today": absences, a closed supplier, retention rules. */
    public function finalize(DateTimeImmutable $now): void
    {
        $today = $now->setTimezone($this->zone);
        $delegations = $this->c->get(DelegationService::class);
        // Current absence (Thomas → Karim) and an upcoming one (Julie → Paul).
        $this->ids['delegation_current'] = $delegations->create($this->org->actor('agent2'), null, $this->org->users['agent3'],
            $today->modify('-2 days')->format('Y-m-d'), $today->modify('+5 days')->format('Y-m-d'), 'Congés annuels')->id;
        $this->ids['delegation_upcoming'] = $delegations->create($this->org->actor('secretariat'), $this->org->users['agent'], $this->org->users['agent5'],
            $today->modify('+10 days')->format('Y-m-d'), $today->modify('+20 days')->format('Y-m-d'), 'Formation')->id;
        $this->expectations['delegation_in_force'] = fn (): array => [
            ((new DelegationRepository($this->pdo, SiteScope::system()))->activeMap($this->org->sites['SIEGE'], $today->format('Y-m-d'))[$this->org->users['agent2']] ?? null) === $this->org->users['agent3'],
            'Thomas → Karim today'];

        if (isset($this->ids['inactive_correspondent'])) {
            $service = $this->c->get(CorrespondentService::class);
            $current = $service->find($this->ids['inactive_correspondent']);
            $service->update($this->org->actor('admin'), $current->id, new CorrespondentInput(
                $current->type, $current->name, $current->organization, $current->email, $current->phone, $current->addressLine1,
                $current->addressLine2, $current->postalCode, $current->city, $current->country, 'Entreprise fermée.', false,
            ));
            $this->expectations['inactive_correspondent_keeps_history'] = fn (): array => [
                (int) $this->value('SELECT is_active FROM correspondents WHERE id = ?', [$this->ids['inactive_correspondent']]) === 0
                && (int) $this->value('SELECT COUNT(*) FROM mails WHERE correspondent_id = ?', [$this->ids['inactive_correspondent']]) > 0,
                'inactive, its mail kept'];
        }

        $retention = $this->c->get(RetentionService::class);
        $retention->create($this->org->actor('admin'), null, 'Archivage des courriers clos', null, 12, RetentionAction::Archive);
        $retention->create($this->org->actor('admin'), null, 'Revue avant élimination', null, 120, RetentionAction::Review);
        // More specific than the global rule: the annexe archives sooner and keeps no files.
        $retention->create($this->org->actor('admin'), $this->org->sites['ANNEXE'], 'Antenne — archivage à 6 mois', null, 6, RetentionAction::Archive);
        $retention->create($this->org->actor('admin'), $this->org->sites['ANNEXE'], 'Antenne — fichiers supprimés à 6 mois', null, 6, RetentionAction::PurgeAttachments);
    }
}
