<?php
/**
 * @floorplan ObjectPage
 *
 * "Mon profil": the signed-in user's account and their password
 * (docs/FIORI_DESIGN.md, "Modèle Object Page"). Identity is read-only: it is managed by an administrator.
 *
 * @var App\Core\View $this
 * @var App\Domain\Auth\User $user
 * @var ?string $siteName
 * @var ?string $departmentName
 * @var array<string, list<string>> $errors
 * @var ?string $totpSecret secret being set up (grouped for reading), null otherwise
 * @var ?string $totpUri
 */
use App\Core\Ui5;
use App\Domain\Auth\PasswordPolicy;

$this->layout('layouts/main');
$fieldLabels = [];
foreach (['current_password', 'new_password', 'new_password_confirmation'] as $field) {
    $fieldLabels[$field] = __('profile.fields.' . $field);
}
$fieldLabels['code'] = __('profile.totp.code');
$fieldLabels['totp_password'] = __('profile.fields.current_password');
$identity = [
    'user.fields.email' => $user->email,
    'user.fields.role' => __($user->role->labelKey()),
    'user.fields.site_id' => $siteName ?? '',
    'user.fields.department_id' => $departmentName ?? __('user.no_department'),
    'user.fields.last_login_at' => $user->lastLoginAt !== null ? local_datetime($user->lastLoginAt) : __('user.never'),
];
?>
<?php $this->start('title') ?><?= e(__('profile.title')) ?><?php $this->stop() ?>
<?php $this->start('main_class') ?>lt-main--page<?php $this->stop() ?>
<?php $this->start('scripts') ?>
<script src="<?= e($basePath) ?>/assets/js/pages/object-page.js"></script>
<?php $this->stop() ?>

<ui5-dynamic-page id="profile-page" class="lt-object-page" data-lt-object-page data-editing show-footer>
    <ui5-dynamic-page-title slot="titleArea">
        <ui5-breadcrumbs slot="breadcrumbs" accessible-name="<?= e(__('profile.title')) ?>">
            <ui5-breadcrumbs-item href="<?= e($basePath) ?>/"><?= e(__('nav.dashboard')) ?></ui5-breadcrumbs-item>
            <ui5-breadcrumbs-item><?= e(__('profile.title')) ?></ui5-breadcrumbs-item>
        </ui5-breadcrumbs>
        <ui5-title slot="heading" level="H1" size="H3" wrapping-type="Normal"><?= e($user->fullName()) ?></ui5-title>
        <div slot="subheading" class="lt-tags">
            <ui5-tag design="Set2" color-scheme="6" hide-state-icon><?= e(__($user->role->labelKey())) ?></ui5-tag>
        </div>
    </ui5-dynamic-page-title>

    <div class="lt-object-page__content">
        <?php if ($user->mustChangePassword): ?>
            <ui5-message-strip class="lt-list-report__result" design="Critical" hide-close-button><?= e(__('profile.must_change')) ?></ui5-message-strip>
        <?php endif; ?>

        <section class="lt-op-section" id="identity" aria-labelledby="identity-title">
            <ui5-title level="H2" size="H4" id="identity-title"><?= e(__('user.sections.identity')) ?></ui5-title>
            <ui5-form layout="S1 M2 L3 XL3" label-span="S12 M12 L12 XL12" item-spacing="Large" accessible-name="<?= e(__('user.sections.identity')) ?>">
                <?php foreach ($identity as $label => $value): ?>
                    <ui5-form-item>
                        <ui5-label slot="labelContent" show-colon><?= e(__($label)) ?></ui5-label>
                        <ui5-text><?= e($value) ?></ui5-text>
                    </ui5-form-item>
                <?php endforeach; ?>
            </ui5-form>
            <ui5-label wrapping-type="Normal"><?= e(__('profile.identity_help')) ?></ui5-label>
        </section>

        <form id="password-form" method="post" novalidate autocomplete="off" action="<?= e($basePath) ?>/profile/password">
            <?= $csrf->field() ?>
            <input type="hidden" name="_method" value="PUT">
            <section class="lt-op-section" id="password-section" aria-labelledby="password-title">
                <ui5-title level="H2" size="H4" id="password-title"><?= e(__('profile.password')) ?></ui5-title>
                <ui5-form layout="S1 M2 L3 XL3" label-span="S12 M12 L12 XL12" item-spacing="Large" accessible-name="<?= e(__('profile.password')) ?>">
                    <?php foreach (['current_password', 'new_password', 'new_password_confirmation'] as $name): ?>
                        <ui5-form-item>
                            <ui5-label slot="labelContent" for="<?= e($name) ?>" required show-colon><?= e(__('profile.fields.' . $name)) ?></ui5-label>
                            <div class="lt-field-stack">
                                <ui5-input id="<?= e($name) ?>" name="<?= e($name) ?>" type="Password" maxlength="<?= e(PasswordPolicy::MAX_BYTES) ?>" required<?= Ui5::state($errors, $name) ?>><?= Ui5::stateMessage($errors, $name) ?></ui5-input>
                                <?php if ($name === 'new_password'): ?>
                                    <ui5-label wrapping-type="Normal"><?= e(__('profile.password_help', ['min' => PasswordPolicy::MIN_LENGTH])) ?></ui5-label>
                                <?php endif; ?>
                            </div>
                        </ui5-form-item>
                    <?php endforeach; ?>
                </ui5-form>
                <ui5-label wrapping-type="Normal"><?= e(__('profile.sessions_help')) ?></ui5-label>
            </section>
        </form>

        <?php if (!$user->mustChangePassword): ?>
            <section class="lt-op-section" id="two-factor" aria-labelledby="two-factor-title" data-totp="<?= $user->totpEnabled ? 'on' : ($totpSecret !== null ? 'setup' : 'off') ?>">
                <ui5-title level="H2" size="H4" id="two-factor-title"><?= e(__('profile.totp.title')) ?></ui5-title>
                <div class="lt-op-block lt-op-block--padded">
                    <?php if ($user->totpEnabled): ?>
                        <div class="lt-tags"><ui5-tag design="Positive"><?= e(__('profile.totp.on')) ?></ui5-tag></div>
                        <form id="totp-disable" method="post" action="<?= e($basePath) ?>/profile/2fa/disable" class="lt-dialog-form" novalidate autocomplete="off" data-lt-validate>
                            <?= $csrf->field() ?>
                            <ui5-text><?= e(__('profile.totp.disable_help')) ?></ui5-text>
                            <ui5-label for="totp_password" required show-colon><?= e(__('profile.fields.current_password')) ?></ui5-label>
                            <ui5-input id="totp_password" name="totp_password" type="Password" required<?= Ui5::state($errors, 'totp_password') ?>><?= Ui5::stateMessage($errors, 'totp_password') ?></ui5-input>
                            <div class="lt-form-actions">
                                <ui5-button design="Default" data-lt-submit="totp-disable"><?= e(__('profile.totp.disable')) ?></ui5-button>
                            </div>
                        </form>
                    <?php elseif ($totpSecret !== null): ?>
                        <form id="totp-enable" method="post" action="<?= e($basePath) ?>/profile/2fa/enable" class="lt-dialog-form" novalidate autocomplete="off" data-lt-validate>
                            <?= $csrf->field() ?>
                            <ui5-text><?= e(__('profile.totp.step1')) ?></ui5-text>
                            <ui5-title level="H3" size="H5" data-totp-secret><?= e($totpSecret) ?></ui5-title>
                            <ui5-link href="<?= e($totpUri) ?>"><?= e(__('profile.totp.open_app')) ?></ui5-link>
                            <ui5-text><?= e(__('profile.totp.step2')) ?></ui5-text>
                            <ui5-label for="code" required show-colon><?= e(__('profile.totp.code')) ?></ui5-label>
                            <ui5-input id="code" name="code" maxlength="7" required<?= Ui5::state($errors, 'code') ?>><?= Ui5::stateMessage($errors, 'code') ?></ui5-input>
                            <div class="lt-form-actions">
                                <ui5-button design="Default" data-lt-submit="totp-enable"><?= e(__('profile.totp.confirm')) ?></ui5-button>
                                <ui5-button design="Transparent" data-lt-submit="totp-cancel"><?= e(__('common.cancel')) ?></ui5-button>
                            </div>
                        </form>
                        <form id="totp-cancel" method="post" action="<?= e($basePath) ?>/profile/2fa/cancel" hidden><?= $csrf->field() ?></form>
                    <?php else: ?>
                        <div class="lt-tags"><ui5-tag design="Neutral"><?= e(__('profile.totp.off')) ?></ui5-tag></div>
                        <form id="totp-start" method="post" action="<?= e($basePath) ?>/profile/2fa/start" class="lt-dialog-form">
                            <?= $csrf->field() ?>
                            <ui5-text><?= e(__('profile.totp.help')) ?></ui5-text>
                            <div class="lt-form-actions">
                                <ui5-button design="Default" data-lt-submit="totp-start"><?= e(__('profile.totp.start')) ?></ui5-button>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>
            </section>
        <?php endif; ?>
    </div>

    <ui5-bar slot="footerArea" design="FloatingFooter" accessible-name="<?= e(__('object.footer')) ?>">
        <?= $this->partial('partials/object-page/messages', ['errors' => $errors, 'labels' => $fieldLabels]) ?>
        <ui5-button slot="endContent" design="Emphasized" data-lt-submit="password-form"><?= e(__('profile.change_password')) ?></ui5-button>
    </ui5-bar>
</ui5-dynamic-page>
