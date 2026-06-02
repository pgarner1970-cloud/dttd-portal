<?php
class DttdSimplePdf {
    private $objects = [];
    private $pages = [];
    private $images = [];
    private $width = 595.28;
    private $height = 841.89;
    private $content = '';

    public function beginPage() { $this->content = ''; }
    private function esc($text) {
        $text = str_replace(['’','‘','“','”','–','—'], ["'","'",'"','"','-','-'], (string)$text);
        $text = str_replace('£', chr(163), $text);
        $text = preg_replace('/[^\x09\x0A\x0D\x20-\xFF]/', '', $text);
        return str_replace(['\\','(',')'], ['\\\\','\\(','\\)'], $text);
    }
    public function text($x, $y, $text, $size = 10, $bold = false, $rgb = [0,0,0]) {
        $font = $bold ? 'F2' : 'F1';
        $this->content .= sprintf("%.3F %.3F %.3F rg ", $rgb[0], $rgb[1], $rgb[2]);
        $this->content .= "BT /{$font} {$size} Tf {$x} {$y} Td (" . $this->esc($text) . ") Tj ET\n";
        $this->content .= "0 0 0 rg\n";
    }

    public function textRight($rightX, $y, $text, $size = 10, $bold = false, $rgb = [0,0,0]) {
        $text = (string)$text;
        $approxWidth = strlen($text) * $size * 0.52;
        $this->text($rightX - $approxWidth, $y, $text, $size, $bold, $rgb);
    }
    public function multiline($x, $y, $text, $size = 9, $maxChars = 48, $lineHeight = 12, $maxLines = 4, $bold = false, $rgb = [0,0,0]) {
        $text = trim(preg_replace('/\s+/', ' ', (string)$text));
        if ($text === '') { return $y; }
        $words = explode(' ', $text);
        $line = '';
        $lines = [];
        foreach ($words as $word) {
            $try = $line === '' ? $word : $line . ' ' . $word;
            if (strlen($try) > $maxChars && $line !== '') {
                $lines[] = $line;
                $line = $word;
            } else {
                $line = $try;
            }
        }
        if ($line !== '') { $lines[] = $line; }
        $lines = array_slice($lines, 0, $maxLines);
        foreach ($lines as $ln) {
            $this->text($x, $y, $ln, $size, $bold, $rgb);
            $y -= $lineHeight;
        }
        return $y;
    }
    public function line($x1,$y1,$x2,$y2,$gray=0.75) {
        $this->content .= sprintf("%.2F G %.2F %.2F m %.2F %.2F l S\n", $gray,$x1,$y1,$x2,$y2);
    }
    public function rect($x,$y,$w,$h,$fill=[1,1,1],$stroke=[0.8,0.8,0.8]) {
        $this->content .= sprintf("%.3F %.3F %.3F rg %.3F %.3F %.3F RG %.2F %.2F %.2F %.2F re B\n", $fill[0],$fill[1],$fill[2],$stroke[0],$stroke[1],$stroke[2],$x,$y,$w,$h);
        $this->content .= "0 0 0 rg 0 G\n";
    }
    public function image($path, $x, $y, $w, $h) {
        if (!isset($this->images[$path])) {
            $info = getimagesize($path);
            $data = file_get_contents($path);
            $name = 'Im' . (count($this->images)+1);
            $this->images[$path] = ['name'=>$name,'width'=>$info[0],'height'=>$info[1],'data'=>$data];
        }
        $name = $this->images[$path]['name'];
        $this->content .= "q {$w} 0 0 {$h} {$x} {$y} cm /{$name} Do Q\n";
    }
    public function imageContain($path, $x, $y, $boxW, $boxH) {
        if (!is_file($path)) { return; }
        $info = getimagesize($path);
        if (!$info || empty($info[0]) || empty($info[1])) { return; }
        $scale = min($boxW / $info[0], $boxH / $info[1]);
        $w = $info[0] * $scale;
        $h = $info[1] * $scale;
        $this->image($path, $x + (($boxW - $w) / 2), $y + (($boxH - $h) / 2), $w, $h);
    }
    public function endPage() { $this->pages[] = $this->content; }
    private function addObject($body) { $this->objects[] = $body; return count($this->objects); }
    public function output($filename='document.pdf') {
        $this->objects = [];
        $catalog = $this->addObject('');
        $pagesObj = $this->addObject('');
        $font1 = $this->addObject('<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>');
        $font2 = $this->addObject('<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>');
        $imageObjs = [];
        foreach ($this->images as $path=>$img) {
            $len = strlen($img['data']);
            $imageObjs[$img['name']] = $this->addObject("<< /Type /XObject /Subtype /Image /Width {$img['width']} /Height {$img['height']} /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length {$len} >>\nstream\n{$img['data']}\nendstream");
        }
        $pageNums=[];
        foreach ($this->pages as $stream) {
            $contentObj = $this->addObject("<< /Length " . strlen($stream) . " >>\nstream\n{$stream}\nendstream");
            $xobjs = '';
            foreach ($imageObjs as $name=>$num) { $xobjs .= "/{$name} {$num} 0 R "; }
            $resources = "<< /Font << /F1 {$font1} 0 R /F2 {$font2} 0 R >>" . ($xobjs ? " /XObject << {$xobjs} >>" : '') . " >>";
            $pageNums[] = $this->addObject("<< /Type /Page /Parent {$pagesObj} 0 R /MediaBox [0 0 {$this->width} {$this->height}] /Resources {$resources} /Contents {$contentObj} 0 R >>");
        }
        $kids = implode(' ', array_map(fn($n)=>"{$n} 0 R", $pageNums));
        $this->objects[$pagesObj-1] = "<< /Type /Pages /Kids [{$kids}] /Count " . count($pageNums) . " >>";
        $this->objects[$catalog-1] = "<< /Type /Catalog /Pages {$pagesObj} 0 R >>";
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [0];
        foreach ($this->objects as $i=>$obj) { $offsets[] = strlen($pdf); $pdf .= ($i+1) . " 0 obj\n{$obj}\nendobj\n"; }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 " . (count($this->objects)+1) . "\n0000000000 65535 f \n";
        for($i=1;$i<count($offsets);$i++) $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        $pdf .= "trailer\n<< /Size " . (count($this->objects)+1) . " /Root {$catalog} 0 R >>\nstartxref\n{$xref}\n%%EOF";
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . preg_replace('/[^a-z0-9._-]/i','_', $filename) . '"');
        header('Content-Length: ' . strlen($pdf));
        echo $pdf;
    }
}
