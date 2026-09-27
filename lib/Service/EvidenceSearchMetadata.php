<?php
namespace OCA\DuplicateFinder\Service;

/** Search labels describe a historical report, never current file validity. */
class EvidenceSearchMetadata {
    public static function fromJson(string $json): array {
        $invalid=['status'=>'invalid','format'=>null];
        try { $record=json_decode($json,true,512,JSON_THROW_ON_ERROR); }
        catch (\JsonException $e) { return $invalid; }
        if (!is_array($record) || ($record['schemaVersion']??null)!==1 || !is_array($record['report']??null)) return $invalid;
        $report=$record['report'];
        if (!in_array($report['status']??null,['passed','corrupt','unsupported','inaccessible','limit','stale','error'],true) || !array_key_exists('format',$report)) return $invalid;
        $format=$report['format'];
        if ($format!==null && (!is_string($format) || $format==='' || strlen($format)>128)) return $invalid;
        return ['status'=>$report['status'],'format'=>$format];
    }
}