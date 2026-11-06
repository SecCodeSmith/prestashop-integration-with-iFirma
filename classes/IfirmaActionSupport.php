<?php
/**
 * SCM iFirma integration module for PrestaShop.
 *
 * @author    SCM Jakub Berechowski <admin@seccodesmith.pl>
 * @copyright 2026 SCM Jakub Berechowski
 * @license   SecCodeSmith Commercial License v1.3
 * SPDX-License-Identifier: LicenseRef-SecCodeSmith-Commercial-1.2
 * See the LICENCE file distributed with this module for the full terms.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once dirname(__FILE__) . '/IfirmaDataRequest.php';

/**
 * What the admin action classes have in common: the module, the result envelope, the error guard and the
 * order lookup.
 *
 * Every public action of a subclass returns array('success' => bool, 'message' => string, 'data' => array)
 * and never throws, so the controller only has to translate the result into JSON.
 */
abstract class IfirmaActionSupport
{
    /** @var Module */
    protected $module;

    /**
     * @param Module $module
     */
    public function __construct(Module $module)
    {
        $this->module = $module;
    }

    /**
     * @param bool   $success
     * @param string $message
     * @param array  $data
     * @return array
     */
    protected function result($success, $message, array $data = array())
    {
        return array('success' => (bool) $success, 'message' => (string) $message, 'data' => $data);
    }

    /**
     * @param Exception|Throwable $e
     * @return array
     */
    protected function failure($e)
    {
        $data = array();
        if ($e instanceof IfirmaDataRequestException) {
            $data = array('errors' => $e->errors, 'code' => $e->code_name);
        }

        return $this->result(false, $e->getMessage(), $data);
    }

    /**
     * Runs the body of an action. Whatever it throws, an Exception or an Error, becomes the failure
     * envelope instead of escaping to the controller.
     *
     * @param callable $action
     * @return array
     */
    protected function guard(callable $action)
    {
        try {
            return $action();
        } catch (Throwable $e) {
            return $this->failure($e);
        }
    }

    /**
     * @param int $idOrder
     * @return Order|null
     */
    protected function loadOrder($idOrder)
    {
        $order = new Order((int) $idOrder);

        return Validate::isLoadedObject($order) ? $order : null;
    }
}
