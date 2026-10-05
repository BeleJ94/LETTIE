<?php
/**
 * @floorplan ObjectPage
 *
 * User account: Object Page in creation or edit mode (docs/FIORI_DESIGN.md, "Modèle Object Page").
 * The password is set at creation, then replaced from a dialog: it is never shown again.
 *
 * @var App\Core\View $this
 * @var ?App\Domain\Auth\User $user
 * @var ?App\Domain\Auth\User $currentUser
 * @var array<string, mixed> $values
 * @var array<string, list<string>> $errors
 * @var list<array{id: int, code: string, name: string, is_active: bool}> $sites
 * @var list<array{id: int, site_id: int, name: string}> $departments
 * @var ?array{activeMails: int, lockedUntil: ?DateTimeImmutable, candidates: list<array<string, mixed>>, history: list<array<string, mixed>>, logins: list<array<string, mixed>>} $details
 */
use App\Core\Ui5;
use App\Domain\Auth\PasswordPolicy;
use App\Domain\Auth\Role;
use App\Domain\Auth\UserRules;

$this->layout('layouts/main');
$title = $user !== null ? $user->fullName() : __('user.new');
$v = static fn (string $key): string => is_scalar($values[$key] ?? null) ? (string) $values[$key] : '';
$isSelf = $user !== null && $currentUser !== null && $user->id === $currentUser->id;
$multiSite = count($sites) > 1;

$roleOptions = [];
foreach (Role::cases() as $role) {
    $roleOptions[$role->value] = __($role->labelKey());
}
$siteNames = array_column($sites, 'name', 'id');
// A deactivated site is no longer offered, except the one the account already belongs to.
$siteOptions = array_column(array_filter($sites, static fn (array $s): bool => $s['is_active'] || (string) $s['id'] === $v('site_id')), 'name', 'id');
$departmentOptions = ['' => __('user.no_department')];
foreach ($departments as $department) {
    $departmentOptions[$department['id']] = $department['name'] . ($multiSite ? ' — ' . ($siteNames[$department['site_id']] ?? '') : '');
}
$localeOptions = [];
foreach (UserRules::LOCALES as $locale) {
    $localeOptions[$locale] = __('locale.' . $locale);
}

$sections = ['identity' => __('user.sections.identity'), 'access' => __('user.sections.access')];
if ($user === null) {
    $sections['password-section'] = __('user.sections.password');
} else {
    $sections['logins'] = __('user.sections.logins');
    $sections['history'] = __('user.sections.history') . ' (' . count($details['history']) . ')';
}
$candidateOptions = ['' => __('user.reassign_none')];
foreach ($details['candidates'] ?? [] as $candidate) {
    $candidateOptions[$candidate['id']] = $candidate['name'] . ' — ' . __('roles.' . $candidate['role']);
}
$departmentNames = array_column($departments, 'name', 'id');
// History: a stored value shown the way the form shows it.
$shown = static function (string $field, mixed $value) use ($siteNames, $departmentNames): string {
    return match (true) {
        $value === null || $value === '' => '',
        is_bool($value) => __($value ? 'common.yes' : 'common.no'),
        $field === 'role' => __('roles.' . $value),
        $field === 'locale' => __('locale.' . $value),
        $field === 'site_id' => (string) ($siteNames[$value] ?? $value),
        $field === 'department_id' => (string) ($departmentNames[$value] ?? $value),
        default => is_scalar($value) ? (string) $value : '',
    };
};
$fieldLabels = [];
foreach (['first_name', 'last_name', 'email', 'role', 'site_id', 'department_id', 'locale', 'is_active', 'password', 'new_password', 'reassign_to'] as $field) {
    $fieldLabels[$field] = __('user.fields.' . $field);
}
// Errors of the password dialog are shown in the dialog, not in the page footer.
$pageErrors = array_diff_key($errors, ['new_password' => true]);
?>
<?php $this->start('title') ?><?= e($title) ?><?php $this->stop() ?>
<?php $this->start('main_class') ?>lt-main--page<?php $this->stop() ?>
<?php $this->start('scripts') ?>
<script src="<?= e($basePath) ?>/assets/js/pages/object-page.js"></script>
<?php $this->stop() ?>

<ui5-dynamic-page id="user-page" class="lt-object-page" data-lt-object-page data-editing show-footer>
    <ui5-dynamic-page-title slot="titleArea">
        <ui5-breadcrumbs slot="breadcrumbs" accessible-name="<?= e(__('user.title')) ?>">
            <ui5-breadcrumbs-item href="<?= e($basePath) ?>/users"><?= e(__('user.title')) ?></ui5-breadcrumbs-item>
            <ui5-breadcrumbs-item><?= e($user !== null ? __('object.editing') : __('object.creating')) ?></ui5-breadcrumbs-item>
        </ui5-breadcrumbs>
        <ui5-title slot="heading" level="H1" size="H3" wrapping-type="Normal"><?= e($title) ?></ui5-title>
        <?php if ($user !== null): ?>
            <div slot="subheading" class="lt-tags">
                <ui5-tag design="Set2" color-scheme="6" hide-state-icon><?= e(__($user->role->labelKey())) ?></ui5-tag>
                <?= Ui5::tag('user_status', $user->isActive ? 'active' : 'inactive') ?>
                <?php if ($isSelf): ?>
                    <ui5-tag design="Information"><?= e(__('user.own_account')) ?></ui5-tag>
                <?php endif; ?>
                <?php if ($details['lockedUntil'] !== null): ?>
                    <ui5-tag design="Negative"><?= e(__('user.locked_until', ['time' => local_datetime($details['lockedUntil'])])) ?></ui5-tag>
                <?php endif; ?>
                <?php if ($user->mustChangePassword): ?>
                    <ui5-tag design="Critical"><?= e(__('user.must_change')) ?></ui5-tag>
                <?php endif; ?>
                <?php if ($user->totpEnabled): ?>
                    <ui5-tag design="Information"><?= e(__('user.totp_on')) ?></ui5-tag>
                <?php endif; ?>
            </div>
            <ui5-toolbar slot="actionsBar" design="Transparent" accessible-name="<?= e(__('object.title_actions')) ?>">
                <?php if ($details['lockedUntil'] !== null): ?>
                    <ui5-toolbar-button icon="synchronize" text="<?= e(__('user.unlock')) ?>" data-lt-submit="user-unlock"></ui5-toolbar-button>
                <?php endif; ?>
                <?php if ($user->totpEnabled): ?>
                    <ui5-toolbar-button icon="undo" text="<?= e(__('user.totp_reset_button')) ?>" data-lt-submit="user-totp-reset"></ui5-toolbar-button>
                <?php endif; ?>
                <ui5-toolbar-button icon="locked" text="<?= e(__('user.reset_password')) ?>" data-lt-open-dialog="user-password"></ui5-toolbar-button>
            </ui5-toolbar>
        <?php endif; ?>
    </ui5-dynamic-page-title>

    <?php if ($user !== null): ?>
        <ui5-dynamic-page-header slot="headerArea">
            <div class="lt-kpis">
                <div class="lt-kpi" data-kpi="email">
                    <ui5-label><?= e(__('user.fields.email')) ?></ui5-label>
                    <ui5-title level="H2" size="H5" wrapping-type="Normal"><?= e($user->email) ?></ui5-title>
                </div>
                <div class="lt-kpi" data-kpi="site">
                    <ui5-label><?= e(__('user.fields.site_id')) ?></ui5-label>
                    <ui5-title level="H2" size="H5" wrapping-type="Normal"><?= e($siteNames[$user->siteId] ?? '') ?></ui5-title>
                </div>
                <div class="lt-kpi" data-kpi="mails">
                    <ui5-label><?= e(__('user.active_mails')) ?></ui5-label>
                    <ui5-title level="H2" size="H5"><?= e($details['activeMails']) ?></ui5-title>
                </div>
                <div class="lt-kpi" data-kpi="last-login">
                    <ui5-label><?= e(__('user.fields.last_login_at')) ?></ui5-label>
                    <ui5-title level="H2" size="H5" wrapping-type="Normal"><?= e($user->lastLoginAt !== null ? local_datetime($user->lastLoginAt) : __('user.never')) ?></ui5-title>
                </div>
            </div>
        </ui5-dynamic-page-header>
    <?php endif; ?>

    <div class="lt-object-page__content">
        <ui5-tabcontainer class="lt-anchor-bar" collapsed data-lt-anchor-bar accessible-name="<?= e(__('object.sections')) ?>">
            <?php foreach ($sections as $id => $label): ?>
                <ui5-tab text="<?= e($label) ?>" data-target="<?= e($id) ?>" <?= $id === 'identity' ? 'selected' : '' ?>></ui5-tab>
            <?php endforeach; ?>
        </ui5-tabcontainer>

        <form id="user-form" method="post" novalidate autocomplete="off"
              action="<?= e($basePath) ?><?= $user !== null ? '/users/' . e($user->id) : '/users' ?>">
            <?= $csrf->field() ?>
            <?php if ($user !== null): ?>
                <input type="hidden" name="_method" value="PUT">
            <?php endif; ?>
            <?php if (!$multiSite): ?>
                <input type="hidden" name="site_id" value="<?= e($v('site_id')) ?>">
            <?php endif; ?>

            <section class="lt-op-section" id="identity" aria-labelledby="identity-title">
                <ui5-title level="H2" size="H4" id="identity-title"><?= e($sections['identity']) ?></ui5-title>
                <ui5-form layout="S1 M2 L3 XL3" label-span="S12 M12 L12 XL12" item-spacing="Large" accessible-name="<?= e($sections['identity']) ?>">
                    <?php foreach ([['first_name', 'Text', 100], ['last_name', 'Text', 100], ['email', 'Email', 190]] as [$name, $type, $max]): ?>
                        <ui5-form-item>
                            <ui5-label slot="labelContent" for="<?= e($name) ?>" required show-colon><?= e(__('user.fields.' . $name)) ?></ui5-label>
                            <ui5-input id="<?= e($name) ?>" name="<?= e($name) ?>" type="<?= e($type) ?>" maxlength="<?= e($max) ?>" required
                                       value="<?= e($v($name)) ?>"<?= Ui5::state($errors, $name) ?>><?= Ui5::stateMessage($errors, $name) ?></ui5-input>
                        </ui5-form-item>
                    <?php endforeach; ?>
                    <ui5-form-item>
                        <ui5-label slot="labelContent" for="locale" required show-colon><?= e(__('user.fields.locale')) ?></ui5-label>
                        <ui5-select id="locale" name="locale"<?= Ui5::state($errors, 'locale') ?>><?= Ui5::options($localeOptions, $v('locale')) ?><?= Ui5::stateMessage($errors, 'locale') ?></ui5-select>
                    </ui5-form-item>
                </ui5-form>
            </section>

            <section class="lt-op-section" id="access" aria-labelledby="access-title">
                <ui5-title level="H2" size="H4" id="access-title"><?= e($sections['access']) ?></ui5-title>
                <ui5-form layout="S1 M2 L3 XL3" label-span="S12 M12 L12 XL12" item-spacing="Large" accessible-name="<?= e($sections['access']) ?>">
                    <ui5-form-item>
                        <ui5-label slot="labelContent" for="role" required show-colon><?= e(__('user.fields.role')) ?></ui5-label>
                        <ui5-select id="role" name="role"<?= Ui5::state($errors, 'role') ?>><?= Ui5::options($roleOptions, $v('role')) ?><?= Ui5::stateMessage($errors, 'role') ?></ui5-select>
                    </ui5-form-item>
                    <?php if ($multiSite): ?>
                        <ui5-form-item>
                            <ui5-label slot="labelContent" for="site_id" required show-colon><?= e(__('user.fields.site_id')) ?></ui5-label>
                            <ui5-select id="site_id" name="site_id"<?= Ui5::state($errors, 'site_id') ?>><?= Ui5::options($siteOptions, $v('site_id')) ?><?= Ui5::stateMessage($errors, 'site_id') ?></ui5-select>
                        </ui5-form-item>
                    <?php endif; ?>
                    <ui5-form-item>
                        <ui5-label slot="labelContent" for="department_id" show-colon><?= e(__('user.fields.department_id')) ?></ui5-label>
                        <div class="lt-field-stack">
                            <ui5-select id="department_id" name="department_id"<?= Ui5::state($errors, 'department_id') ?>><?= Ui5::options($departmentOptions, $v('department_id')) ?><?= Ui5::stateMessage($errors, 'department_id') ?></ui5-select>
                            <?php if ($multiSite): ?>
                                <ui5-label wrapping-type="Normal"><?= e(__('user.department_help')) ?></ui5-label>
                            <?php endif; ?>
                        </div>
                    </ui5-form-item>
                    <ui5-form-item>
                        <div class="lt-field-stack">
                            <ui5-checkbox id="is_active" name="is_active" value="1" text="<?= e(__('user.fields.is_active')) ?>" <?= $v('is_active') === '1' ? 'checked' : '' ?><?= Ui5::state($errors, 'is_active') ?>></ui5-checkbox>
                            <ui5-label wrapping-type="Normal"><?= e($errors['is_active'][0] ?? __('user.inactive_help')) ?></ui5-label>
                        </div>
                    </ui5-form-item>
                    <?php if ($user !== null && $user->isActive && $details['activeMails'] > 0): ?>
                        <ui5-form-item>
                            <ui5-label slot="labelContent" for="reassign_to" show-colon><?= e(__('user.fields.reassign_to')) ?></ui5-label>
                            <div class="lt-field-stack">
                                <ui5-select id="reassign_to" name="reassign_to"<?= Ui5::state($errors, 'reassign_to') ?>><?= Ui5::options($candidateOptions, $v('reassign_to')) ?><?= Ui5::stateMessage($errors, 'reassign_to') ?></ui5-select>
                                <ui5-label wrapping-type="Normal"><?= e(__('user.reassign_help', ['count' => $details['activeMails']])) ?></ui5-label>
                            </div>
                        </ui5-form-item>
                    <?php endif; ?>
                </ui5-form>
            </section>

            <?php if ($user === null): ?>
                <section class="lt-op-section" id="password-section" aria-labelledby="password-title">
                    <ui5-title level="H2" size="H4" id="password-title"><?= e($sections['password-section']) ?></ui5-title>
                    <ui5-form layout="S1 M2 L3 XL3" label-span="S12 M12 L12 XL12" item-spacing="Large" accessible-name="<?= e($sections['password-section']) ?>">
                        <ui5-form-item>
                            <ui5-label slot="labelContent" for="password" required show-colon><?= e(__('user.fields.password')) ?></ui5-label>
                            <div class="lt-field-stack">
                                <ui5-input id="password" name="password" type="Password" maxlength="<?= e(PasswordPolicy::MAX_BYTES) ?>" required<?= Ui5::state($errors, 'password') ?>><?= Ui5::stateMessage($errors, 'password') ?></ui5-input>
                                <ui5-label wrapping-type="Normal"><?= e(__('user.password_help', ['min' => PasswordPolicy::MIN_LENGTH])) ?></ui5-label>
                            </div>
                        </ui5-form-item>
                    </ui5-form>
                </section>
            <?php endif; ?>
        </form>

        <?php if ($user !== null): ?>
            <form id="user-unlock" method="post" action="<?= e($basePath) ?>/users/<?= e($user->id) ?>/unlock" hidden><?= $csrf->field() ?></form>
            <form id="user-totp-reset" method="post" action="<?= e($basePath) ?>/users/<?= e($user->id) ?>/2fa/reset" hidden
                  data-lt-confirm="<?= e(__('user.totp_reset_confirm', ['name' => $user->fullName()])) ?>"><?= $csrf->field() ?></form>

            <section class="lt-op-section" id="logins" aria-labelledby="logins-title">
                <ui5-title level="H2" size="H4" id="logins-title"><?= e(__('user.sections.logins')) ?></ui5-title>
                <?php if ($details['logins'] === []): ?>
                    <div class="lt-op-block lt-op-block--empty"><ui5-text><?= e(__('user.logins_empty')) ?></ui5-text></div>
                <?php else: ?>
                    <div class="lt-op-block">
                        <ui5-list separators="Inner" accessible-name="<?= e(__('user.sections.logins')) ?>">
                            <?php foreach ($details['logins'] as $login): ?>
                                <ui5-li type="Inactive" description="<?= e($login['ip'] ?? '') ?>"
                                        additional-text="<?= e(__($login['succeeded'] ? 'user.login_ok' : 'user.login_failed')) ?>"
                                        additional-text-state="<?= $login['succeeded'] ? 'Positive' : 'Negative' ?>"><?= e(local_datetime($login['at'])) ?></ui5-li>
                            <?php endforeach; ?>
                        </ui5-list>
                    </div>
                <?php endif; ?>
                <ui5-label wrapping-type="Normal"><?= e(__('user.logins_help')) ?></ui5-label>
            </section>

            <section class="lt-op-section" id="history" aria-labelledby="history-title">
                <ui5-title level="H2" size="H4" id="history-title"><?= e(__('user.sections.history')) ?></ui5-title>
                <?php if ($details['history'] === []): ?>
                    <div class="lt-op-block lt-op-block--empty"><ui5-text><?= e(__('user.history_empty')) ?></ui5-text></div>
                <?php else: ?>
                    <div class="lt-op-block lt-op-block--padded">
                        <ui5-timeline accessible-name="<?= e(__('user.sections.history')) ?>">
                            <?php foreach ($details['history'] as $entry): ?>
                                <ui5-timeline-item icon="history" title-text="<?= e(__('user.history.' . $entry['action'])) ?>"
                                                   subtitle-text="<?= e($entry['user_name'] ?? '') ?> · <?= e(local_datetime($entry['created_at'])) ?>">
                                    <?php if ($entry['action'] === 'update'): ?>
                                        <?php foreach ($entry['new_values'] as $field => $new): ?>
                                            <ui5-text class="lt-change"><?= e(__('object.changed', [
                                                'field' => __('user.fields.' . $field),
                                                'old' => $shown((string) $field, $entry['old_values'][$field] ?? null),
                                                'new' => $shown((string) $field, $new),
                                            ])) ?></ui5-text>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </ui5-timeline-item>
                            <?php endforeach; ?>
                        </ui5-timeline>
                    </div>
                <?php endif; ?>
            </section>
        <?php endif; ?>
    </div>

    <ui5-bar slot="footerArea" design="FloatingFooter" accessible-name="<?= e(__('object.footer')) ?>">
        <?= $this->partial('partials/object-page/messages', ['errors' => $pageErrors, 'labels' => $fieldLabels]) ?>
        <ui5-button slot="endContent" design="Emphasized" data-lt-submit="user-form"><?= e(__('common.save')) ?></ui5-button>
        <ui5-button slot="endContent" design="Transparent" data-lt-href="/users"><?= e(__('common.cancel')) ?></ui5-button>
    </ui5-bar>
</ui5-dynamic-page>

<?php if ($user !== null): ?>
    <ui5-dialog id="user-password" data-lt-form-dialog state="Critical" header-text="<?= e(__('user.reset_title')) ?>"<?= isset($errors['new_password']) ? ' open' : '' ?>>
        <form method="post" action="<?= e($basePath) ?>/users/<?= e($user->id) ?>/password" class="lt-dialog-form" novalidate autocomplete="off">
            <?= $csrf->field() ?>
            <ui5-text><?= e(__('user.reset_help')) ?></ui5-text>
            <ui5-label for="new_password" required show-colon><?= e(__('user.fields.new_password')) ?></ui5-label>
            <ui5-input id="new_password" name="new_password" type="Password" maxlength="<?= e(PasswordPolicy::MAX_BYTES) ?>" required<?= Ui5::state($errors, 'new_password') ?>><?= Ui5::stateMessage($errors, 'new_password') ?></ui5-input>
            <ui5-label wrapping-type="Normal"><?= e(__('user.password_help', ['min' => PasswordPolicy::MIN_LENGTH])) ?></ui5-label>
        </form>
        <div slot="footer" class="lt-dialog-footer">
            <ui5-button design="Emphasized" data-lt-dialog-submit><?= e(__('user.reset_confirm')) ?></ui5-button>
            <ui5-button design="Transparent" data-lt-dialog-cancel><?= e(__('common.cancel')) ?></ui5-button>
        </div>
    </ui5-dialog>
<?php endif; ?>
