<?php
/**
 * @author    SCM Jakub Berechowski
 * @license   SecCodeSmith Commercial License v1.3
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_ . 'ifirma/classes/IfirmaActionSupport.php';
require_once _PS_MODULE_DIR_ . 'ifirma/classes/IfirmaApiClient.php';
require_once _PS_MODULE_DIR_ . 'ifirma/classes/IfirmaDownloadBudget.php';
require_once _PS_MODULE_DIR_ . 'ifirma/classes/IfirmaHttpOutput.php';
require_once _PS_MODULE_DIR_ . 'ifirma/classes/IfirmaHttpStatus.php';
require_once _PS_MODULE_DIR_ . 'ifirma/classes/IfirmaPermission.php';
require_once _PS_MODULE_DIR_ . 'ifirma/classes/IfirmaReceipt.php';
require_once _PS_MODULE_DIR_ . 'ifirma/classes/IfirmaRateDecision.php';
require_once _PS_MODULE_DIR_ . 'ifirma/classes/IfirmaRateLimitedException.php';
require_once _PS_MODULE_DIR_ . 'ifirma/classes/IfirmaReceiptService.php';
require_once _PS_MODULE_DIR_ . 'ifirma/classes/IfirmaAdminActions.php';
require_once _PS_MODULE_DIR_ . 'ifirma/classes/IfirmaBulkRunner.php';
require_once _PS_MODULE_DIR_ . 'ifirma/classes/IfirmaInput.php';
require_once _PS_MODULE_DIR_ . 'ifirma/classes/IfirmaIssueFeed.php';
require_once _PS_MODULE_DIR_ . 'ifirma/classes/IfirmaDocumentListSort.php';
require_once _PS_MODULE_DIR_ . 'ifirma/classes/IfirmaOrderGridIndicator.php';
require_once _PS_MODULE_DIR_ . 'ifirma/classes/IfirmaDeliveryRetryView.php';
require_once _PS_MODULE_DIR_ . 'ifirma/classes/IfirmaSchema.php';
require_once _PS_MODULE_DIR_ . 'ifirma/classes/IfirmaReceiptActions.php';

class AdminIfirmaController extends ModuleAdminController
{
    const LEVEL_VIEW = 'view';
    const LEVEL_ISSUE = 'issue';
    const LEVEL_MANAGE = 'manage';

    /**
     * The permission level every action needs (spec: receipt-access-control, design D1). No view-level
     * action name may start with add, new, edit, update, options, status or delete: PrestaShop 9 maps
     * those prefixes to core permissions. BulkRun is not listed: its level depends on the posted kind
     * (see levelFor()).
     *
     * @var array action => level
     */
    private static $levels = array(
        'CreateReceipt' => self::LEVEL_ISSUE,
        'IssueAnyway' => self::LEVEL_ISSUE,
        'LinkCandidate' => self::LEVEL_ISSUE,
        'RegisterContractor' => self::LEVEL_ISSUE,
        'IssueFromList' => self::LEVEL_ISSUE,
        'UpdateStatus' => self::LEVEL_MANAGE,
        'ResendDocument' => self::LEVEL_MANAGE,
        'SendToKsef' => self::LEVEL_MANAGE,
        'RefreshStatus' => self::LEVEL_MANAGE,
        'ApproveRequest' => self::LEVEL_MANAGE,
        'RejectRequest' => self::LEVEL_MANAGE,
        'DownloadPdf' => self::LEVEL_VIEW,
        'IssueFeed' => self::LEVEL_VIEW,
        'IssueFeedSeen' => self::LEVEL_VIEW,
        'CustomerCheck' => self::LEVEL_VIEW,
        'CustomerSearch' => self::LEVEL_VIEW,
        'CustomerCountRemote' => self::LEVEL_VIEW,
        'CustomerLink' => self::LEVEL_VIEW,
        'CustomerUnlink' => self::LEVEL_VIEW,
        'CustomerRegister' => self::LEVEL_ISSUE,
    );

    /**
     * The actions that only read and so may be sent with GET (the PDF link of the panels is a plain link). Every
     * other action, and any action added later, changes state or sends a request to iFirma and runs only for a
     * POST: the core's token check is skipped when the shop runs without PS_SECURITY_TOKEN, so the method is the
     * second guard against a link or an image tag that triggers an action.
     *
     * @var string[]
     */
    private static $readOnlyActions = array(
        'DownloadPdf',
        'IssueFeed',
        'CustomerCheck',
        'CustomerSearch',
        'CustomerCountRemote',
    );

    /** @var array bulk kind => level */
    private static $bulkLevels = array(
        'issue' => self::LEVEL_ISSUE,
        'retry' => self::LEVEL_ISSUE,
        'resend' => self::LEVEL_MANAGE,
        'refresh' => self::LEVEL_MANAGE,
    );

    public function __construct()
    {
        $this->bootstrap = true;

        // the documents list (spec: receipt-documents-list, design D3): the legacy list of the receipt table
        $this->table = 'ifirma_receipt';
        $this->className = 'IfirmaReceipt';
        $this->identifier = 'id_ifirma_receipt';
        $this->list_id = 'ifirma_receipt';
        $this->lang = false;
        $this->allow_export = false;
        $this->list_no_link = true;
        $this->_defaultOrderBy = 'id_ifirma_receipt';
        $this->_defaultOrderWay = 'DESC';
        $this->_select = 'o.reference AS order_reference, CONCAT(c.firstname, \' \', c.lastname) AS customer_name, \'\' AS actions, '
            . IfirmaDocumentListSort::rankSelect('a');
        $this->_join = 'LEFT JOIN `' . _DB_PREFIX_ . 'orders` o ON o.id_order = a.id_order '
            . 'LEFT JOIN `' . _DB_PREFIX_ . 'customer` c ON c.id_customer = a.id_customer';
        if ($this->attentionPresetActive()) {
            $this->_where .= ' AND (' . IfirmaIssueFeed::issuePredicate('a') . ')';
        }

        parent::__construct();

        if (!($this->module instanceof Module)) {
            $this->module = Module::getInstanceByName('ifirma');
        }
        if ($this->module instanceof Module) {
            $this->defineList();
        }
    }

    /**
     * The core sets the list base URL in init(): the preset is appended there so the sort, pagination and
     * filter links of the list carry it.
     */
    public function init()
    {
        parent::init();

        if ($this->attentionPresetActive()) {
            self::$currentIndex .= '&ifirma_preset=attention';
        }
    }

    /**
     * The core renders the list only for an employee who may view the tab, so the schema is not touched before the
     * access decision (init() runs for everyone).
     *
     * @return string
     */
    public function renderList()
    {
        $this->ensureReceiptRetryColumns();

        return parent::renderList();
    }

    /**
     * The list and the actions read the retry columns of the receipt table; an upgrade that did not add them yet must
     * not hide them.
     */
    private function ensureReceiptRetryColumns()
    {
        IfirmaSchema::ensureReceiptRetryColumns();
    }

    /**
     * The generic list actions of the core class (save, add, update, delete, status, position, export, the
     * core bulk entries) would change the rows of the receipt table: only the list and filter actions and the
     * three bulk fallbacks of this controller stay. Ajax requests are not touched.
     */
    public function initProcess()
    {
        parent::initProcess();

        if ($this->ajax) {
            return;
        }
        $allowed = array('', 'list', 'filter', 'reset_filters', 'bulkretryissuance', 'bulkresend', 'bulkrefresh');
        $action = strtolower(IfirmaInput::scalar($this->action));
        $display = IfirmaInput::scalar($this->display);
        if (in_array($action, $allowed, true) && in_array($display, array('', 'list'), true)) {
            return;
        }

        $this->action = '';
        $this->display = null;
        $this->unavailable();
    }

    private function unavailable()
    {
        $this->errors[] = $this->module->l('This action is not available on this page.', 'adminifirmacontroller');
    }

    // the generic actions of the core class, refused even when something sets them (initProcess() clears them first)
    public function processSave()
    {
        $this->unavailable();

        return false;
    }

    public function processAdd()
    {
        $this->unavailable();

        return false;
    }

    public function processUpdate()
    {
        $this->unavailable();

        return false;
    }

    public function processDelete()
    {
        $this->unavailable();

        return false;
    }

    public function processStatus()
    {
        $this->unavailable();

        return false;
    }

    public function processPosition()
    {
        $this->unavailable();

        return false;
    }

    public function processExport($text_delimiter = '"')
    {
        $this->unavailable();

        return false;
    }

    public function processBulkDelete()
    {
        $this->unavailable();

        return false;
    }

    public function processBulkEnableSelection()
    {
        $this->unavailable();

        return false;
    }

    public function processBulkDisableSelection()
    {
        $this->unavailable();

        return false;
    }

    /**
     * @return bool whether the needs-attention preset was asked for
     */
    private function attentionPresetActive()
    {
        return IfirmaInput::scalar(Tools::getValue('ifirma_preset')) === 'attention';
    }

    /**
     * The columns and the bulk entries of the documents list. Runs after the core constructor so the module
     * can word them.
     */
    private function defineList()
    {
        $statuses = array(
            IfirmaReceipt::STATUS_CREATED => $this->module->l('Created', 'adminifirmacontroller'),
            IfirmaReceipt::STATUS_PAID => $this->module->l('Paid', 'adminifirmacontroller'),
            IfirmaReceipt::STATUS_ERROR => $this->module->l('Error', 'adminifirmacontroller'),
            IfirmaReceipt::STATUS_UNKNOWN => $this->module->l('Unknown', 'adminifirmacontroller'),
            IfirmaReceipt::STATUS_DUPLICATE_SUSPECTED => $this->module->l('Suspected duplicate', 'adminifirmacontroller'),
            IfirmaReceipt::STATUS_HELD => $this->module->l('Held', 'adminifirmacontroller'),
            IfirmaReceipt::STATUS_QUEUED => $this->module->l('Queued', 'adminifirmacontroller'),
        );
        $sources = array(
            IfirmaReceipt::SOURCE_MODULE => $this->module->l('Module', 'adminifirmacontroller'),
            IfirmaReceipt::SOURCE_EXTERNAL => $this->module->l('External', 'adminifirmacontroller'),
        );
        $this->fields_list = array(
            'id_ifirma_receipt' => array(
                'title' => $this->module->l('ID', 'adminifirmacontroller'),
                'filter_key' => 'a!id_ifirma_receipt',
                'class' => 'fixed-width-xs',
            ),
            'order_reference' => array(
                'title' => $this->module->l('Order', 'adminifirmacontroller'),
                'filter_key' => 'o!reference',
            ),
            'customer_name' => array(
                'title' => $this->module->l('Customer', 'adminifirmacontroller'),
                'havingFilter' => true,
            ),
            'ifirma_number' => array(
                'title' => $this->module->l('Document number', 'adminifirmacontroller'),
                'filter_key' => 'a!ifirma_number',
                'callback' => 'renderNumber',
            ),
            'status' => array(
                'title' => $this->module->l('Status', 'adminifirmacontroller'),
                'type' => 'select',
                'list' => $statuses,
                'filter_key' => 'a!status',
                'order_key' => IfirmaDocumentListSort::RANK_ALIAS,
                'filter_type' => 'string',
            ),
            'source' => array(
                'title' => $this->module->l('Source', 'adminifirmacontroller'),
                'type' => 'select',
                'list' => $sources,
                'filter_key' => 'a!source',
                'filter_type' => 'string',
            ),
            'delivery_status' => array(
                'title' => $this->module->l('Delivery', 'adminifirmacontroller'),
                'type' => 'select',
                'list' => $this->deliveryLabels(),
                'callback' => 'renderDelivery',
                'filter_key' => 'a!delivery_status',
                'filter_type' => 'string',
            ),
            'ksef_status' => array(
                'title' => $this->module->l('KSeF', 'adminifirmacontroller'),
                'type' => 'select',
                'list' => array('WYSLANA' => 'WYSLANA', 'PRZYJETA' => 'PRZYJETA', 'BLAD' => 'BLAD'),
                'filter_key' => 'a!ksef_status',
                'filter_type' => 'string',
            ),
            'issue_date' => array(
                'title' => $this->module->l('Issue date', 'adminifirmacontroller'),
                'type' => 'date',
                'filter_key' => 'a!issue_date',
            ),
            'last_error' => array(
                'title' => $this->module->l('Last error', 'adminifirmacontroller'),
                'callback' => 'renderLastError',
                'orderby' => false,
                'search' => false,
            ),
            'date_upd' => array(
                'title' => $this->module->l('Updated', 'adminifirmacontroller'),
                'type' => 'datetime',
                'filter_key' => 'a!date_upd',
            ),
            'actions' => array(
                'title' => $this->module->l('Actions', 'adminifirmacontroller'),
                'callback' => 'renderRowActions',
                'orderby' => false,
                'search' => false,
                'class' => 'text-right',
            ),
        );
        $this->bulk_actions = array(
            'retryIssuance' => array('text' => $this->module->l('Retry issuance', 'adminifirmacontroller'), 'icon' => 'icon-refresh'),
            'resend' => array('text' => $this->module->l('Send again', 'adminifirmacontroller'), 'icon' => 'icon-envelope'),
            'refresh' => array('text' => $this->module->l('Refresh from iFirma', 'adminifirmacontroller'), 'icon' => 'icon-download'),
        );
    }

    /**
     * The core throws on a direction that is not asc or desc, and it takes the direction from the request, then from the
     * list's cookie, then from the list's state and its default. The cookie is stored by the employee's browser: here the
     * direction is resolved the same way and anything that is not asc or desc, from whichever source, sorts ascending and
     * never reaches the core or the SQL.
     *
     * @param string|null $orderDirection
     * @return string ASC or DESC
     */
    protected function checkOrderDirection($orderDirection)
    {
        $requested = IfirmaInput::scalar(Tools::getValue($this->list_id . 'Orderway'));
        if ($requested !== '' && !IfirmaDocumentListSort::isDirection($requested)) {
            return IfirmaDocumentListSort::ASCENDING;
        }

        $effective = $this->effectiveOrderDirection($orderDirection);
        if (!IfirmaDocumentListSort::isDirection($effective)) {
            return IfirmaDocumentListSort::ASCENDING;
        }

        return parent::checkOrderDirection($effective);
    }

    /**
     * @param mixed $orderDirection the direction the core was asked for
     * @return mixed the first of: that direction, the list's cookie, the list's state, its default
     */
    private function effectiveOrderDirection($orderDirection)
    {
        $cookie = $this->context->cookie;
        $prefix = str_replace(array('admin', 'controller'), '', Tools::strtolower(get_class($this)));
        $stored = isset($cookie->{$prefix . $this->list_id . 'Orderway'}) ? $cookie->{$prefix . $this->list_id . 'Orderway'} : false;
        foreach (array($orderDirection, $stored, $this->_orderWay, $this->_defaultOrderWay) as $candidate) {
            if (!empty($candidate)) {
                return $candidate;
            }
        }

        return '';
    }

    /**
     * Records of the same state are listed newest first in both directions of the state sort.
     *
     * @param string|null $orderBy
     * @param string|null $orderDirection
     * @return string
     */
    protected function getOrderByClause($orderBy, $orderDirection)
    {
        $clause = parent::getOrderByClause($orderBy, $orderDirection);

        return IfirmaDocumentListSort::withTieBreak($clause, $this->_orderBy, $this->_orderWay);
    }

    /**
     * @return string[] delivery status => the word for it
     */
    private function deliveryLabels()
    {
        return array(
            IfirmaReceipt::DELIVERY_NONE => $this->module->l('Not sent', 'adminifirmacontroller'),
            IfirmaReceipt::DELIVERY_SENT => $this->module->l('Sent', 'adminifirmacontroller'),
            IfirmaReceipt::DELIVERY_FAILED => $this->module->l('Failed', 'adminifirmacontroller'),
            IfirmaReceipt::DELIVERY_SKIPPED => $this->module->l('Skipped', 'adminifirmacontroller'),
            IfirmaReceipt::DELIVERY_AWAITING_KSEF => $this->module->l('Awaiting KSeF', 'adminifirmacontroller'),
        );
    }

    /**
     * List callback: the delivery state in words and, for a failed delivery, where the automatic retry stands. The
     * state of another version of the module is shown as it is. Both texts are escaped here (the list prints the result as HTML).
     *
     * @param mixed $value the delivery status
     * @param array $row   the record (delivery_attempts and delivery_retry_at are missing until the columns exist)
     * @return string
     */
    public function renderDelivery($value, $row = array())
    {
        $status = IfirmaInput::scalar($value);
        $labels = $this->deliveryLabels();
        $cell = htmlspecialchars(isset($labels[$status]) ? $labels[$status] : $status, ENT_QUOTES, 'UTF-8');
        $row = is_array($row) ? $row : array();
        $retryView = new IfirmaDeliveryRetryView(IfirmaDeliveryRetryView::translatedWords($this->module));
        $retry = $retryView->text(
            $status,
            isset($row['delivery_attempts']) ? (int) $row['delivery_attempts'] : 0,
            isset($row['delivery_retry_at']) ? IfirmaInput::scalar($row['delivery_retry_at']) : ''
        );

        return $retry === '' ? $cell : $cell . '<br><small class="text-muted">' . htmlspecialchars($retry, ENT_QUOTES, 'UTF-8') . '</small>';
    }

    /**
     * List callback: the document number, else the iFirma identifier.
     *
     * @param mixed $value
     * @param array $row
     * @return string
     */
    public function renderNumber($value, $row)
    {
        $number = trim(IfirmaInput::scalar($value));
        if ($number === '' && is_array($row) && isset($row['ifirma_id'])) {
            $number = trim(IfirmaInput::scalar($row['ifirma_id']));
        }

        return htmlspecialchars($number, ENT_QUOTES, 'UTF-8');
    }

    /**
     * List callback: the last error cut to 80 characters.
     *
     * @param mixed $value
     * @param array $row
     * @return string
     */
    public function renderLastError($value, $row = array())
    {
        $text = trim(IfirmaInput::scalar($value));
        if (function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') > 80 : strlen($text) > 80) {
            $text = (function_exists('mb_substr') ? mb_substr($text, 0, 77, 'UTF-8') : substr($text, 0, 77)) . '...';
        }

        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }

    /**
     * List callback: the buttons of one row, each only for an employee holding its level. The data-kind /
     * data-id buttons are run by the bulk script like a bulk entry with one id.
     *
     * @param mixed $value
     * @param array $row
     * @return string
     */
    public function renderRowActions($value, $row)
    {
        $row = is_array($row) ? $row : array();
        $employee = isset($this->context->employee) ? $this->context->employee : null;
        $idReceipt = isset($row['id_ifirma_receipt']) ? (int) $row['id_ifirma_receipt'] : 0;
        $idOrder = isset($row['id_order']) ? (int) $row['id_order'] : 0;
        $status = isset($row['status']) ? (string) $row['status'] : '';
        $issued = isset($row['ifirma_id']) && trim((string) $row['ifirma_id']) !== '' && $status !== IfirmaReceipt::STATUS_ERROR;
        $link = $this->context->link;
        $html = '';

        if ($idOrder > 0) {
            $html .= '<a class="btn btn-default btn-sm" href="' . htmlspecialchars($link->getAdminLink('AdminOrders', true, array(), array('id_order' => $idOrder, 'vieworder' => 1)), ENT_QUOTES, 'UTF-8') . '">'
                . htmlspecialchars($this->module->l('Open order', 'adminifirmacontroller'), ENT_QUOTES, 'UTF-8') . '</a> ';
        }
        if (!$issued && IfirmaPermission::canIssue($employee)) {
            $html .= $this->rowButton('retry', $idReceipt, $this->module->l('Retry issuance', 'adminifirmacontroller'));
        }
        if ($issued && IfirmaPermission::canManage($employee)) {
            $html .= $this->rowButton('resend', $idReceipt, $this->module->l('Send again', 'adminifirmacontroller'));
            $html .= $this->rowButton('refresh', $idReceipt, $this->module->l('Refresh', 'adminifirmacontroller'));
        }
        if ($issued && IfirmaPermission::canView($employee)) {
            $url = $link->getAdminLink('AdminIfirma', true, array(), array('ajax' => 1, 'action' => 'DownloadPdf', 'id_order' => $idOrder, 'id_ifirma_receipt' => $idReceipt));
            $html .= '<a class="btn btn-default btn-sm" href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">'
                . htmlspecialchars($this->module->l('Download PDF', 'adminifirmacontroller'), ENT_QUOTES, 'UTF-8') . '</a> ';
        }

        return trim($html);
    }

    /**
     * @param string $kind
     * @param int    $id
     * @param string $label
     * @return string
     */
    private function rowButton($kind, $id, $label)
    {
        return '<button type="button" class="btn btn-default btn-sm js-ifirma-row" data-kind="' . htmlspecialchars($kind, ENT_QUOTES, 'UTF-8')
            . '" data-id="' . (int) $id . '">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</button> ';
    }

    /**
     * The native bulk entries are replaced by the batch runner of the page script; without JavaScript they
     * only explain themselves.
     */
    public function processBulkRetryIssuance()
    {
        $this->bulkNeedsJavascript();

        return false;
    }

    public function processBulkResend()
    {
        $this->bulkNeedsJavascript();

        return false;
    }

    public function processBulkRefresh()
    {
        $this->bulkNeedsJavascript();

        return false;
    }

    private function bulkNeedsJavascript()
    {
        $this->errors[] = $this->module->l('The bulk actions of this page need JavaScript.', 'adminifirmacontroller');
    }

    /**
     * Adds the needs-attention preset button (and a way back to all documents when it is active).
     */
    public function initPageHeaderToolbar()
    {
        parent::initPageHeaderToolbar();

        if ($this->attentionPresetActive()) {
            $this->page_header_toolbar_btn['ifirma_all'] = array(
                'href' => $this->context->link->getAdminLink('AdminIfirma', true),
                'desc' => $this->module->l('All documents', 'adminifirmacontroller'),
                'icon' => 'process-icon-eraser',
            );
        } else {
            $this->page_header_toolbar_btn['ifirma_attention'] = array(
                'href' => $this->attentionUrl(),
                'desc' => $this->module->l('Needs attention', 'adminifirmacontroller'),
                'icon' => 'process-icon-flag',
            );
        }
    }

    /**
     * The list has nothing to add: drop the core "Add new" button.
     */
    public function initToolbar()
    {
        parent::initToolbar();
        unset($this->toolbar_btn['new']);
        unset($this->page_header_toolbar_btn['new']);
    }

    /**
     * @return string the documents list with the needs-attention preset
     */
    private function attentionUrl()
    {
        return $this->context->link->getAdminLink('AdminIfirma', true, array(), array('ifirma_preset' => 'attention'));
    }

    /**
     * The level an action needs.
     *
     * @param string      $action the action name without the ajaxProcess prefix
     * @param string|null $kind   the posted kind of a BulkRun
     * @return string|null view, issue or manage; null for an unknown action. An unknown bulk kind needs
     *                     manage.
     */
    public static function levelFor($action, $kind = null)
    {
        if ($action === 'BulkRun') {
            return isset(self::$bulkLevels[(string) $kind]) ? self::$bulkLevels[(string) $kind] : self::LEVEL_MANAGE;
        }

        return isset(self::$levels[$action]) ? self::$levels[$action] : null;
    }

    /**
     * @param string $action the action name without the ajaxProcess prefix
     * @return bool true when the action may run only for a POST (BulkRun and every action not listed as read-only)
     */
    public static function requiresPost($action)
    {
        return !in_array($action, self::$readOnlyActions, true);
    }

    /**
     * @return bool true when the request is a POST (the raw request method, so a `_method` parameter does not count)
     */
    protected function requestIsPost()
    {
        return IfirmaInput::isPost();
    }

    /**
     * Runs an action body when the employee of the context holds the level the action needs; otherwise
     * answers 403 with the failure envelope. The token has been checked by the core before this point.
     *
     * @param string   $action
     * @param callable $body
     */
    protected function handle($action, $body)
    {
        $kind = ($action === 'BulkRun') ? IfirmaInput::scalar(Tools::getValue('kind')) : null;
        $level = self::levelFor($action, $kind);
        $employee = isset($this->context->employee) ? $this->context->employee : null;

        if ($level === null || !$this->employeeHolds($employee, $level)) {
            $this->statusHeader(403);
            $this->output(array(
                'success' => false,
                'message' => $this->denialMessage($level),
                'data' => array(),
            ));

            return;
        }

        if (self::requiresPost($action) && !$this->requestIsPost()) {
            $this->statusHeader(405);
            $this->output(array(
                'success' => false,
                'message' => $this->module->l('This action must be sent with POST.', 'adminifirmacontroller'),
                'data' => array(),
            ));

            return;
        }

        $this->ensureReceiptRetryColumns();
        call_user_func($body);
    }

    /**
     * @param object|null $employee
     * @param string      $level
     * @return bool
     */
    private function employeeHolds($employee, $level)
    {
        if ($level === self::LEVEL_ISSUE) {
            return IfirmaPermission::canIssue($employee);
        }
        if ($level === self::LEVEL_MANAGE) {
            return IfirmaPermission::canManage($employee);
        }

        return IfirmaPermission::canView($employee);
    }

    /**
     * @param string|null $level
     * @return string
     */
    private function denialMessage($level)
    {
        if ($level === self::LEVEL_ISSUE) {
            return $this->module->l('You do not have permission to issue iFirma documents.', 'adminifirmacontroller');
        }
        if ($level === self::LEVEL_VIEW) {
            return $this->module->l('You do not have permission to see iFirma documents.', 'adminifirmacontroller');
        }

        return $this->module->l('You do not have permission to manage iFirma documents.', 'adminifirmacontroller');
    }

    /**
     * Sends the status line of an HTTP status. Overridable so tests can record the code.
     *
     * @param int $code
     *
     * @codeCoverageIgnore header() cannot be sent from the CLI harness; the tests override this twin and record the code
     */
    protected function statusHeader($code)
    {
        header(IfirmaHttpStatus::statusLine($code));
    }

    /**
     * @return IfirmaAdminActions
     */
    protected function actions()
    {
        return new IfirmaAdminActions($this->module);
    }

    /**
     * @return int
     */
    private function employeeId()
    {
        return (isset($this->context->employee) && $this->context->employee) ? (int) $this->context->employee->id : 0;
    }

    /**
     * @return int
     */
    private function orderId()
    {
        return (int) IfirmaInput::scalar(Tools::getValue('id_order'));
    }

    public function ajaxProcessCreateReceipt()
    {
        $this->handle('CreateReceipt', function () {
            $this->respond($this->actions()->createReceipt($this->orderId(), $this->employeeId()));
        });
    }

    public function ajaxProcessIssueAnyway()
    {
        $this->handle('IssueAnyway', function () {
            $this->respond($this->actions()->issueAnyway($this->orderId(), $this->employeeId()));
        });
    }

    public function ajaxProcessLinkCandidate()
    {
        $this->handle('LinkCandidate', function () {
            $this->respond($this->actions()->linkCandidate(
                $this->orderId(),
                trim(IfirmaInput::scalar(Tools::getValue('faktura_id'))),
                $this->employeeId()
            ));
        });
    }

    public function ajaxProcessUpdateStatus()
    {
        $this->handle('UpdateStatus', function () {
            $amount = Tools::getValue('amount', null);
            // the raw value goes to the service, which validates it (IfirmaInput::decimal)
            $amount = ($amount === null || $amount === '' || $amount === false) ? null : $amount;

            $this->respond($this->actions()->updateStatus($this->orderId(), $amount));
        });
    }

    public function ajaxProcessResendDocument()
    {
        $this->handle('ResendDocument', function () {
            $this->respond($this->actions()->resend($this->orderId()));
        });
    }

    public function ajaxProcessSendToKsef()
    {
        $this->handle('SendToKsef', function () {
            $this->respond($this->actions()->sendToKsef($this->orderId()));
        });
    }

    public function ajaxProcessRefreshStatus()
    {
        $this->handle('RefreshStatus', function () {
            $this->respond($this->actions()->refreshStatus($this->orderId()));
        });
    }

    public function ajaxProcessRegisterContractor()
    {
        $this->handle('RegisterContractor', function () {
            $result = $this->actions()->registerContractor($this->orderId());

            $this->respond(array(
                'success' => $result['success'],
                'message' => $result['message'],
                'data' => array('contractor' => $result['data']),
            ));
        });
    }

    /**
     * @return int
     */
    private function customerId()
    {
        return (int) IfirmaInput::scalar(Tools::getValue('id_customer'));
    }

    /**
     * Whether the employee may edit customers (spec: customer-contractor-panel, D8).
     *
     * @return bool
     */
    private function canEditCustomers()
    {
        $idProfile = (isset($this->context->employee) && $this->context->employee) ? (int) $this->context->employee->id_profile : 0;
        if ($idProfile <= 0) {
            return false;
        }

        return (bool) Access::isGranted('ROLE_MOD_TAB_ADMINCUSTOMERS_UPDATE', $idProfile);
    }

    /**
     * Refuses the request when the employee may not edit customers.
     * Responds (and ends the request) with the permission message.
     *
     * @return bool true when the action may proceed
     */
    private function requireEditCustomers()
    {
        if ($this->canEditCustomers()) {
            return true;
        }
        $this->statusHeader(403);
        $this->respond(array(
            'success' => false,
            'message' => $this->module->l('You do not have permission to edit customers.', 'adminifirmacontroller'),
            'data' => array(),
        ));

        return false;
    }

    public function ajaxProcessCustomerCheck()
    {
        $this->handle('CustomerCheck', function () {
            $this->respond($this->actions()->customerCheck($this->customerId()));
        });
    }

    public function ajaxProcessCustomerSearch()
    {
        $this->handle('CustomerSearch', function () {
            $posted = Tools::getValue('criteria', null);
            $criteria = array();
            foreach (array('firstname', 'lastname', 'company', 'nip', 'email', 'phone') as $key) {
                $criteria[$key] = (is_array($posted) && isset($posted[$key]) && !is_array($posted[$key])) ? (string) $posted[$key] : '';
            }

            $this->respond($this->actions()->customerSearch($criteria));
        });
    }

    public function ajaxProcessCustomerLink()
    {
        $this->handle('CustomerLink', function () {
            if (!$this->requireEditCustomers()) {
                return;
            }
            $this->respond($this->actions()->customerLink($this->customerId(), trim(IfirmaInput::scalar(Tools::getValue('identifier')))));
        });
    }

    public function ajaxProcessCustomerUnlink()
    {
        $this->handle('CustomerUnlink', function () {
            if (!$this->requireEditCustomers()) {
                return;
            }
            $this->respond($this->actions()->customerUnlink($this->customerId()));
        });
    }

    public function ajaxProcessCustomerRegister()
    {
        $this->handle('CustomerRegister', function () {
            if (!$this->requireEditCustomers()) {
                return;
            }
            $this->respond($this->actions()->registerCustomer($this->customerId()));
        });
    }

    public function ajaxProcessCustomerCountRemote()
    {
        $this->handle('CustomerCountRemote', function () {
            $this->respond($this->actions()->customerCountRemote($this->customerId()));
        });
    }

    public function ajaxProcessApproveRequest()
    {
        $this->handle('ApproveRequest', function () {
            $data = Tools::getValue('data', null);
            $edited = is_array($data) ? $data : null;

            $this->respond($this->actions()->approveRequest(
                (int) IfirmaInput::scalar(Tools::getValue('id_request')),
                $this->employeeId(),
                $edited,
                IfirmaInput::scalar(Tools::getValue('admin_note'))
            ));
        });
    }

    public function ajaxProcessRejectRequest()
    {
        $this->handle('RejectRequest', function () {
            $this->respond($this->actions()->rejectRequest(
                (int) IfirmaInput::scalar(Tools::getValue('id_request')),
                $this->employeeId(),
                IfirmaInput::scalar(Tools::getValue('reason'))
            ));
        });
    }

    /**
     * @return IfirmaBulkRunner
     */
    protected function bulkRunner()
    {
        return new IfirmaBulkRunner($this->module);
    }

    /**
     * @return IfirmaIssueFeed
     */
    protected function issueFeed()
    {
        return new IfirmaIssueFeed();
    }

    /**
     * Runs one batch of a bulk action (design D4). The page script sends the selection in batches and
     * re-sends "remaining" first.
     */
    public function ajaxProcessBulkRun()
    {
        $this->handle('BulkRun', function () {
            $kind = IfirmaInput::scalar(Tools::getValue('kind'));
            $posted = Tools::getValue('ids', null);
            if (!in_array($kind, IfirmaBulkRunner::kinds(), true)) {
                $this->respond($this->failure($this->module->l('Unknown bulk action.', 'adminifirmacontroller')));

                return;
            }
            if (!is_array($posted)) {
                $this->respond($this->failure($this->module->l('The ids must be a list.', 'adminifirmacontroller')));

                return;
            }
            $ids = array();
            foreach ($posted as $id) {
                $id = is_array($id) ? 0 : (int) $id;
                if ($id > 0 && !in_array($id, $ids, true)) {
                    $ids[] = $id;
                }
            }
            if (!$ids) {
                $this->respond($this->failure($this->module->l('Select at least one row.', 'adminifirmacontroller')));

                return;
            }

            $size = IfirmaBulkRunner::batchSize($kind);
            $batch = array_slice($ids, 0, $size);
            $overflow = array_slice($ids, $size);
            $totals = array();
            $postedTotals = Tools::getValue('totals', null);
            if (is_array($postedTotals)) {
                foreach ($postedTotals as $outcome => $count) {
                    if (!is_array($count)) {
                        $totals[(string) $outcome] = (int) $count;
                    }
                }
            }

            $data = $this->bulkRunner()->run($kind, $batch, $this->employeeId(), array(
                'final' => (bool) Tools::getValue('final'),
                'previous_totals' => $totals,
            ));
            if (isset($data['stopped']) && $data['stopped'] === '') {
                $data['remaining'] = array_merge(isset($data['remaining']) ? $data['remaining'] : array(), $overflow);
            }
            if ($kind === IfirmaBulkRunner::KIND_ISSUE) {
                foreach ($data['results'] as $i => $result) {
                    if (!empty($result['receipt']) && is_array($result['receipt'])) {
                        $data['results'][$i]['cell'] = $this->cellFor($result['receipt'], (int) $result['id_order']);
                    }
                }
            }

            $this->respond(array(
                'success' => true,
                'message' => sprintf(
                    $this->module->l('Processed %1$d of %2$d.', 'adminifirmacontroller'),
                    (int) $data['processed'],
                    count($ids)
                ),
                'data' => $data,
            ));
        });
    }

    /**
     * Issues the document of one order from the orders list; answers the new inner HTML of its cell.
     */
    public function ajaxProcessIssueFromList()
    {
        $this->handle('IssueFromList', function () {
            $idOrder = $this->orderId();
            $result = $this->actions()->createReceipt($idOrder, $this->employeeId());
            $data = isset($result['data']) && is_array($result['data']) ? $result['data'] : array();
            $receipt = IfirmaReceipt::getByOrder($idOrder);
            $receiptArray = $receipt instanceof IfirmaReceipt
                ? (new IfirmaReceiptActions($this->module))->receiptToArray($receipt)
                : array();

            $out = array(
                'outcome' => $this->listOutcome(!empty($result['success']), isset($data['outcome']) ? (string) $data['outcome'] : ''),
                'cell' => $this->cellFor($receiptArray, $idOrder),
                'receipt' => $receiptArray,
            );

            $this->output(array(
                'success' => !empty($result['success']),
                'message' => (string) $result['message'],
                'data' => $out,
                'receipt' => $receiptArray,
            ));
        });
    }

    /**
     * @param bool   $ok
     * @param string $pipelineOutcome
     * @return string issued | adopted | suspected | held | failed
     */
    private function listOutcome($ok, $pipelineOutcome)
    {
        if ($ok) {
            return $pipelineOutcome === IfirmaPipelineResult::OUTCOME_ADOPTED ? 'adopted' : 'issued';
        }

        return in_array($pipelineOutcome, array('suspected', 'held'), true) ? $pipelineOutcome : 'failed';
    }

    /**
     * The inner HTML of the "iFirma" cell of an order for a record array (receiptToArray()).
     *
     * @param array $receipt
     * @param int   $idOrder
     * @return string
     */
    private function cellFor(array $receipt, $idOrder)
    {
        $identifier = isset($receipt['ifirma_id']) ? trim((string) $receipt['ifirma_id']) : '';
        $status = isset($receipt['status']) ? (string) $receipt['status'] : '';
        $issued = $identifier !== '' && $status !== IfirmaReceipt::STATUS_ERROR;
        $number = isset($receipt['ifirma_number']) ? trim((string) $receipt['ifirma_number']) : '';

        return IfirmaOrderGridIndicator::cell(array(
            'id_order' => (int) $idOrder,
            IfirmaOrderGridIndicator::FIELD_NUMBER => $issued ? ($number !== '' ? $number : $identifier) : '',
            IfirmaOrderGridIndicator::FIELD_STATUS => $issued ? $status : '',
            IfirmaOrderGridIndicator::FIELD_STATE => $status,
        ), $this->gridLabels(), true);
    }

    /**
     * @return array the words of the grid cell; empty when the module does not offer them (the cell falls back
     *               to English)
     */
    private function gridLabels()
    {
        if (is_object($this->module) && method_exists($this->module, 'gridCellLabels')) {
            $labels = $this->module->gridCellLabels();

            return is_array($labels) ? $labels : array();
        }

        return array();
    }

    /**
     * @return array kind code => label
     */
    private function kindLabels()
    {
        if (is_object($this->module) && method_exists($this->module, 'issueKindLabels')) {
            $labels = $this->module->issueKindLabels();

            return is_array($labels) ? $labels : array();
        }

        return array();
    }

    /**
     * @param string $message
     * @return array
     */
    private function failure($message)
    {
        return array('success' => false, 'message' => $message, 'data' => array());
    }

    /**
     * The recent documents that need attention, for the notification tab of the header.
     */
    public function ajaxProcessIssueFeed()
    {
        $this->handle('IssueFeed', function () {
            $feed = $this->issueFeed();
            $link = $this->context->link;
            $labels = $this->kindLabels();
            $items = array();
            foreach ($feed->latest() as $item) {
                $kind = (string) $item['kind'];
                $time = (string) $item['time'];
                $stamp = $time !== '' ? strtotime($time) : false;
                $items[] = array(
                    'id_order' => (int) $item['id_order'],
                    'reference' => (string) $item['reference'],
                    'kind' => $kind,
                    'kind_label' => isset($labels[$kind]) ? (string) $labels[$kind] : $kind,
                    'number' => (string) $item['number'],
                    'time' => $stamp ? date('Y-m-d H:i', $stamp) : $time,
                    'url' => $link->getAdminLink('AdminOrders', true, array(), array('id_order' => (int) $item['id_order'], 'vieworder' => 1)),
                );
            }

            $this->output(array(
                'success' => true,
                'message' => '',
                'data' => array(
                    'unseen' => $feed->unseenCount($feed->seenAt($this->employeeId())),
                    'items' => $items,
                    'list_url' => $this->attentionUrl(),
                ),
            ));
        });
    }

    /**
     * Marks the feed as seen by the employee now.
     */
    public function ajaxProcessIssueFeedSeen()
    {
        $this->handle('IssueFeedSeen', function () {
            $this->issueFeed()->markSeen($this->employeeId());

            $this->output(array('success' => true, 'message' => '', 'data' => array('unseen' => 0)));
        });
    }

    public function ajaxProcessDownloadPdf()
    {
        $employee = isset($this->context->employee) ? $this->context->employee : null;
        if (!IfirmaPermission::canView($employee)) {
            $this->textResponse(403, $this->denialMessage(self::LEVEL_VIEW));
            return;
        }

        $idOrder = $this->orderId();
        $idReceipt = (int) IfirmaInput::scalar(Tools::getValue('id_ifirma_receipt'));
        if ($idReceipt > 0) {
            // the list row names its record; it has to belong to the posted order
            $receipt = new IfirmaReceipt($idReceipt);
            if (!Validate::isLoadedObject($receipt) || (int) $receipt->id_order !== $idOrder) {
                $receipt = null;
            }
        } else {
            $receipt = IfirmaReceipt::getByOrder($idOrder);
        }

        if (!$receipt || !$receipt->isIssued()) {
            $this->textResponse(404, $this->module->l('Document not found.', 'adminifirmacontroller'));
            return;
        }

        $budget = $this->employeeBudget();
        if (!$budget->take()) {
            $this->refusedByRateLimit($budget->secondsUntilRoom(), $this->module->l('The PDF was not downloaded: the back-office download limit is reached. Try again in a moment.', 'adminifirmacontroller'));
            return;
        }

        try {
            $service = IfirmaReceiptService::build($this->module);
            $pdf = $service->fetchPdf($receipt, (bool) Tools::getValue('duplicate'));
        } catch (IfirmaRateLimitedException $e) {
            $budget->release();
            $this->refusedByRateLimit($e->getRetryAfterSeconds(), $this->rateLimitedDownloadMessage($e->getWindow()));
            return;
        } catch (Throwable $e) {
            $this->textResponse(502, $this->downloadFailureMessage($e));
            return;
        }

        $this->streamPdf($pdf, $receipt);
    }

    /**
     * @return IfirmaDownloadBudget the budget of all back-office downloads; protected so a test can fix its clock
     */
    protected function employeeBudget()
    {
        return IfirmaDownloadBudget::forEmployees();
    }

    /**
     * The employee is told which window refused the download; the customer front never is.
     *
     * @param string $window one of the IfirmaRateDecision::WINDOW_* constants
     * @return string
     */
    private function rateLimitedDownloadMessage($window)
    {
        if ($window === IfirmaRateDecision::WINDOW_DAY) {
            return $this->module->l('The PDF was not downloaded: the day request limit is reached. Try again later.', 'adminifirmacontroller');
        }
        if ($window === IfirmaRateDecision::WINDOW_BACKOFF) {
            return $this->module->l('The PDF was not downloaded: iFirma asked the module to wait before the next request. Try again shortly.', 'adminifirmacontroller');
        }

        return $this->module->l('The PDF was not downloaded: the minute request limit is reached. Try again in a moment.', 'adminifirmacontroller');
    }

    /**
     * Answers a 429 with the seconds to wait and ends the request. Overridable so tests can capture the answer;
     * the caller returns right after the call.
     *
     * @codeCoverageIgnore exit ends the request; the tests override this twin, sendLimitAnswer() is tested on its own
     *
     * @param int    $retryAfterSeconds
     * @param string $text
     */
    protected function refusedByRateLimit($retryAfterSeconds, $text)
    {
        $this->sendLimitAnswer($retryAfterSeconds, $text);
        exit;
    }

    /**
     * @param int    $retryAfterSeconds
     * @param string $text
     */
    protected function sendLimitAnswer($retryAfterSeconds, $text)
    {
        IfirmaHttpOutput::tooManyRequests($retryAfterSeconds, $text);
    }

    /**
     * The text of a failed download: the message of a module exception, otherwise the generic text,
     * with the original class and message in the PrestaShop log.
     *
     * @param Throwable $e
     * @return string
     */
    private function downloadFailureMessage($e)
    {
        if (IfirmaActionSupport::isModuleException($e)) {
            return $e->getMessage();
        }
        PrestaShopLogger::addLog('iFirma: ' . get_class($e) . ': ' . $e->getMessage(), 3);

        return $this->module->l('Internal error; details are in the PrestaShop log.', 'adminifirmacontroller');
    }

    /**
     * Answers a plain-text body with its status line and ends the request. Overridable so tests can
     * capture the answer; the caller returns right after the call.
     *
     * @param int    $status HTTP status code
     * @param string $text   the body
     *
     * @codeCoverageIgnore header() and exit end the request; the tests override this twin and record the answer
     */
    protected function textResponse($status, $text)
    {
        header(IfirmaHttpStatus::statusLine($status));
        header('Content-Type: text/plain; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        echo $text;
        exit;
    }

    protected function streamPdf($pdf, IfirmaReceipt $receipt)
    {
        IfirmaHttpOutput::pdf($pdf, $receipt->ifirma_number ? $receipt->ifirma_number : $receipt->ifirma_id);
        exit;
    }

    /**
     * @param array $result array('success', 'message', 'data')
     */
    private function respond(array $result)
    {
        if (!empty($result['success'])) {
            $this->ajaxSuccess($result['message'], isset($result['data']) ? $result['data'] : array());
        }

        $this->ajaxError($result['message'], isset($result['data']) ? $result['data'] : array());
    }

    private function ajaxSuccess($message, array $data = array())
    {
        $this->output(array(
            'success' => true,
            'message' => $message,
            'data' => $data,
            'receipt' => isset($data['receipt']) ? $data['receipt'] : array(),
        ));
    }

    /**
     * Sends the JSON envelope and ends the request. Overridable so tests can capture the payload.
     *
     * @codeCoverageIgnore header() and exit end the request; the tests override this twin, decodeMessages() is tested
     *
     * @param array $payload
     */
    protected function output(array $payload)
    {
        header('Content-Type: application/json');
        echo json_encode($this->decodeMessages($payload));
        exit;
    }

    /**
     * Module::l() HTML-escapes translated text and the scripts render messages as text: decode every
     * `message` and `kind_label` string, at any depth. Other keys (the `cell` markup, URLs) stay as they are.
     *
     * @param array $payload
     *
     * @return array
     */
    protected function decodeMessages(array $payload)
    {
        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $payload[$key] = $this->decodeMessages($value);
            } elseif (is_string($value) && ($key === 'message' || $key === 'kind_label')) {
                $payload[$key] = html_entity_decode($value, ENT_QUOTES, 'UTF-8');
            }
        }

        return $payload;
    }

    private function ajaxError($message, array $data = array())
    {
        $this->output(array(
            'success' => false,
            'message' => $message,
            'data' => $data,
            'receipt' => isset($data['receipt']) ? $data['receipt'] : array(),
        ));
    }
}
