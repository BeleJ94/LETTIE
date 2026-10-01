/**
 * What goes into the vendored UI5 Web Components bundle (tools/ui5/build-bundle.mjs).
 * Add a component here, rebuild with `npm run ui5:build` and commit the result:
 * the server never runs npm.
 */

/** Themes kept in the bundle (docs/FIORI_DESIGN.md §3). */
export const THEMES = ['sap_horizon', 'sap_horizon_dark', 'sap_horizon_hcb', 'sap_horizon_hcw'];

/** Languages kept for the UI5 texts and CLDR data (docs/FIORI_DESIGN.md §8). */
export const LOCALES = ['fr', 'en'];

/** @ui5/webcomponents (main) */
export const MAIN = [
    'Bar', 'Breadcrumbs', 'BreadcrumbsItem', 'BusyIndicator', 'Button', 'Card', 'CardHeader',
    'CheckBox', 'DatePicker', 'DateTimePicker', 'Dialog', 'FileUploader', 'Form', 'FormGroup',
    'FormItem', 'Icon', 'Input', 'Label', 'Link', 'List', 'ListItemCustom', 'ListItemStandard',
    'MessageStrip', 'Option', 'Panel', 'Popover', 'Select', 'SuggestionItem', 'Switch',
    'Table', 'TableCell', 'TableGrowing', 'TableHeaderCell', 'TableHeaderRow', 'TableRow',
    'Tag', 'Text', 'TextArea', 'Title', 'Toast', 'Toolbar', 'ToolbarButton', 'ToolbarSpacer',
];

/** @ui5/webcomponents-fiori */
export const FIORI = [
    'DynamicPage', 'DynamicPageHeader', 'DynamicPageTitle', 'IllustratedMessage',
    'NotificationList', 'NotificationListGroupItem', 'NotificationListItem',
    'ShellBar', 'ShellBarItem', 'SideNavigation', 'SideNavigationGroup', 'SideNavigationItem',
    'SideNavigationSubItem', 'Timeline', 'TimelineItem', 'UploadCollection', 'UploadCollectionItem',
    'Wizard', 'WizardStep',
];

/** @ui5/webcomponents-fiori illustrations (empty states, error pages). */
export const ILLUSTRATIONS = [
    'EmptyList', 'ErrorScreen', 'NoActivities', 'NoData', 'NoEntries', 'NoFilterResults',
    'NoMail', 'NoNotifications', 'NoSearchResults', 'PageNotFound', 'UnableToLoad', 'UnableToUpload',
];

/** @ui5/webcomponents-icons (SAP icons, <ui5-icon name="…">). */
export const ICONS = [
    'accept', 'add', 'alert', 'appointment-2', 'attachment', 'bar-chart', 'bell', 'business-card',
    'calendar', 'close-command-field', 'decline', 'delete', 'document', 'document-text', 'download',
    'edit', 'email', 'employee', 'error', 'excel-attachment', 'filter', 'history', 'home',
    'hint', 'inbox', 'information', 'journey-arrive', 'journey-depart', 'light-mode', 'dark-mode',
    'log', 'menu2', 'message-error', 'message-information', 'message-success', 'message-warning',
    'navigation-right-arrow', 'notes', 'pdf-attachment', 'print', 'refresh', 'response', 'save',
    'search', 'settings', 'share-2', 'sort', 'synchronize', 'user-settings', 'upload', 'visits',
    'action-settings', 'collections-management', 'group', 'person-placeholder', 'locked', 'workflow-tasks',
];
