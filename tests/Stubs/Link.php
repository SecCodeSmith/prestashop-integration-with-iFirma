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

class Link
{
    public function getModuleLink($module, $controller = 'default', array $params = [], $ssl = null, $idLang = null, $idShop = null, $relativeProtocol = false)
    {
        return 'https://shop.test/module/' . $module . '/' . $controller . '?' . http_build_query($params);
    }

    public function getAdminLink($controller, $withToken = true, $sfRouteParams = [], $params = [])
    {
        return 'https://shop.test/admin/index.php?controller=' . $controller
            . ($withToken ? '&token=admin-token-' . $controller : '');
    }

    public function getPageLink($controller, $ssl = null, $idLang = null, $request = null, $requestUrlEncode = false, $idShop = null, $relativeProtocol = false)
    {
        return 'https://shop.test/' . $controller . ($request ? '?' . (is_array($request) ? http_build_query($request) : $request) : '');
    }
}
