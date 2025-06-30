<?php
// download.php
if (isset($_GET['file']) && file_exists($_GET['file'])) {
    header('Content-Description: File Transfer');
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="'.basename($_GET['file']).'"');
    header('Expires: 0');
    header('Cache-Control: must-revalidate');
    header('Pragma: public');
    header('Content-Length: ' . filesize($_GET['file']));
    readfile($_GET['file']);
    exit;
}