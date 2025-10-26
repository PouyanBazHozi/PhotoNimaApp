<?php
session_start();
require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_SESSION['csrf_token']) || $_SERVER['HTTP_X_CSRF_TOKEN'] !== $_SESSION['csrf_token']) {
    http_response_code(403);
    exit('Unauthorized');
}

$data = json_decode(file_get_contents('php://input'), true);
$type = $data['type'] ?? '';

switch ($type) {
    case 'pdf':
        header('Content-Type: application/pdf');
        require_once 'vendor/autoload.php'; // Assuming TCPDF or similar is installed
        $pdf = new TCPDF();
        $pdf->AddPage();
        $pdf->SetFont('dejavusans', '', 12);
        $pdf->Write(0, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $pdf->Output('dashboard.pdf', 'D');
        break;

    case 'excel':
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment;filename="dashboard.xlsx"');
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray(array_keys($data), null, 'A1');
        $sheet->fromArray([$data], null, 'A2');
        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $writer->save('php://output');
        break;

    case 'csv':
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment;filename="dashboard.csv"');
        $output = fopen('php://output', 'w');
        fputcsv($output, array_keys($data));
        fputcsv($output, $data);
        fclose($output);
        break;

    default:
        http_response_code(400);
        exit('Invalid export type');
}