<?php
$files = ["empleados.xlsx", "equipos.xlsx"];
foreach($files as $file) {
    echo "\n=== $file ===\n";
    $zip = new ZipArchive;
    if ($zip->open($file) === TRUE) {
        $sharedStringsXML = $zip->getFromName("xl/sharedStrings.xml");
        $sheet1XML = $zip->getFromName("xl/worksheets/sheet1.xml");
        $zip->close();
        if($sharedStringsXML && $sheet1XML) {
            $strings = simplexml_load_string($sharedStringsXML);
            $sheet = simplexml_load_string($sheet1XML);
            $shared = [];
            foreach($strings->si as $si) {
                if(isset($si->t)) $shared[] = (string)$si->t;
                elseif(isset($si->r)) {
                    $s = "";
                    foreach($si->r as $r) $s .= (string)$r->t;
                    $shared[] = $s;
                }
            }
            $row1 = $sheet->sheetData->row[0];
            $headers = [];
            foreach($row1->c as $c) {
                if((string)$c["t"] == "s") {
                    $headers[] = $shared[(int)$c->v];
                } else {
                    $headers[] = (string)$c->v;
                }
            }
            print_r($headers);
        }
    }
}
