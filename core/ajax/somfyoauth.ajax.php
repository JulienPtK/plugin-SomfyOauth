<?php

/*
 * This file is part of the NextDom software (https://github.com/NextDom or http://nextdom.github.io).
 * Copyright (c) 2018 NextDom.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, version 2.
 *
 * This program is distributed in the hope that it will be useful, but
 * WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU
 * General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

try {
    require_once dirname(__FILE__) . '/../../../../core/php/core.inc.php';
    include_file('core', 'authentification', 'php');

    if (!isConnect('admin')) {
        throw new Exception(__('401 - Accès non autorisé', __FILE__));
    }
    
    ajax::init();

    // action qui permet d'obtenir l'ensemble des eqLogic
    if (init('action') == 'getAll') {
        $eqLogics = eqLogic::byType('apiai');
        // la liste des équipements
        foreach ($eqLogics as $eqLogic) {
            $data['id'] = $eqLogic->getId();
            $data['humanSidebar'] = $eqLogic->getHumanName(true, false);
            $data['humanContainer'] = $eqLogic->getHumanName(true, true);
            $return[] = $data;
        }
        ajax::success($return);
    }
    // action qui permet d'effectuer la sauvegarde des donées en asynchrone
    if (init('action') == 'saveStack') {
        $params = init('params');
        ajax::success(apiai::saveStack($params));
    }
  
    if (init('action') == 'syncEQWithSomfy') {
        somfyoauth::syncEQWithSomfy();
        ajax::success();
    }
    
    if (init('action') == 'refreshStates') {
        somfyoauth::refreshAll();
        ajax::success();
    }

    throw new Exception(__('Aucune méthode correspondante à : ', __FILE__) . init('action'));
    /*     * *********Catch exeption*************** */
} catch (Exception $e) {
    ajax::error(displayExeption($e), $e->getCode());
}
?>
