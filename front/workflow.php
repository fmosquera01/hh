<?php
declare(strict_types=1);

use GlpiPlugin\Hh\RepairWorkflow;

include('../../../inc/includes.php');

Session::checkLoginUser();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Html::displayErrorAndDie('Método no permitido');
}
Session::checkCSRF($_POST);

$itemtype = trim((string) ($_POST['itemtype'] ?? ''));
$itemsId = filter_var($_POST['items_id'] ?? 0, FILTER_VALIDATE_INT);
$action = trim((string) ($_POST['action'] ?? ''));

if ($itemsId === false || $itemsId < 1 || !RepairWorkflow::processAction($itemtype, (int) $itemsId, $action)) {
    Session::addMessageAfterRedirect('No se pudo actualizar el estado. Verifique permisos, estado actual y configuración.', false, ERROR);
} else {
    Session::addMessageAfterRedirect('El estado del activo se actualizó correctamente y quedó registrado en el historial.', true, INFO);
}

if (class_exists($itemtype) && method_exists($itemtype, 'getFormURL')) {
    Html::redirect($itemtype::getFormURL() . '?id=' . (int) $itemsId);
}
Html::redirect('/plugins/hh/front/index.php');
