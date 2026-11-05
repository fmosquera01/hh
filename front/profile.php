<?php
declare(strict_types=1);

use GlpiPlugin\Hh\Rights;

include('../../../inc/includes.php');

Session::checkLoginUser();
if (!Session::haveRight('profile', UPDATE)) {
    Html::displayRightError();
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['save_hh_rights'])) {
    Html::displayErrorAndDie('Solicitud no válida.');
}
Session::checkCSRF($_POST);

$profilesId = filter_var($_POST['profiles_id'] ?? 0, FILTER_VALIDATE_INT);
if ($profilesId === false || $profilesId < 1) {
    Html::displayErrorAndDie('Perfil no válido.');
}

$allowed = [
    Rights::READ, Rights::CREATE, Rights::UPDATE, Rights::APPROVE,
    Rights::CATALOG, Rights::POINTS, Rights::POINT_VALUE,
];
$mask = 0;
$submitted = $_POST['rights'] ?? [];
if (is_array($submitted)) {
    foreach ($submitted as $value) {
        $bit = filter_var($value, FILTER_VALIDATE_INT);
        if ($bit !== false && in_array($bit, $allowed, true)) {
            $mask |= $bit;
        }
    }
}

ProfileRight::updateProfileRights((int)$profilesId, ['plugin_hh' => $mask]);
Html::redirect('/front/profile.form.php?id=' . (int)$profilesId);
