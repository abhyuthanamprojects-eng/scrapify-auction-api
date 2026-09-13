<?php

namespace App\Services\Verification;

final class BusinessEntityClassifier
{
    public static function code(
        ?string $legalName,
        ?string $constitution = null,
        ?string $taxpayerType = null,
        ?string $gstin = null,
    ): string {
        $text = strtoupper(trim(implode(' ', array_filter([$legalName, $constitution, $taxpayerType]))));

        return match (true) {
            preg_match('/\bLIMITED LIABILITY PARTNERSHIP\b|\bLLP\b/', $text) === 1 => 'LLP',
            preg_match('/\bONE PERSON COMPANY\b|\bOPC\b/', $text) === 1 => 'OPC',
            preg_match('/\bPRIVATE LIMITED\b|\bPVT\.?\s*LTD\b/', $text) === 1 => 'PRIVATE_LIMITED',
            preg_match('/\bPUBLIC LIMITED\b|\bPUBLIC LTD\b/', $text) === 1 => 'PUBLIC_LIMITED',
            preg_match('/\bPROPRIETORSHIP\b|\bPROPRIETOR\b/', $text) === 1 => 'PROPRIETORSHIP',
            preg_match('/\bPARTNERSHIP\b/', $text) === 1 => 'PARTNERSHIP',
            preg_match('/\bTRUST\b/', $text) === 1 => 'TRUST',
            preg_match('/\bSOCIETY\b/', $text) === 1 => 'SOCIETY',
            preg_match('/\bGOVERNMENT\b|\bGOVT\b/', $text) === 1 => 'GOVERNMENT',
            preg_match('/\bHUF\b|HINDU UNDIVIDED FAMILY/', $text) === 1 => 'HUF',
            default => self::gstPanType($gstin),
        };
    }

    public static function label(string $code): string
    {
        return match (strtoupper($code)) {
            'PRIVATE_LIMITED' => 'Private Limited',
            'PUBLIC_LIMITED' => 'Public Limited',
            'LLP' => 'LLP',
            'OPC' => 'One Person Company',
            'PARTNERSHIP' => 'Partnership Firm',
            'PROPRIETORSHIP' => 'Proprietorship',
            'TRUST' => 'Trust',
            'SOCIETY' => 'Society',
            'GOVERNMENT' => 'Government Entity',
            'HUF' => 'HUF',
            'INDIVIDUAL' => 'Individual / Proprietor',
            'COMPANY' => 'Company',
            default => 'Other Legal Entity',
        };
    }

    public static function details(
        ?string $legalName,
        ?string $constitution = null,
        ?string $taxpayerType = null,
        ?string $gstin = null,
    ): array {
        $code = self::code($legalName, $constitution, $taxpayerType, $gstin);

        return [
            'entity_type' => $code,
            'entity_type_label' => self::label($code),
        ];
    }

    private static function gstPanType(?string $gstin): string
    {
        $gstin = strtoupper(preg_replace('/\s+/', '', (string) $gstin));
        $panType = strlen($gstin) >= 6 ? $gstin[5] : null;

        return match ($panType) {
            'C' => 'COMPANY',
            'F' => 'PARTNERSHIP',
            'P' => 'INDIVIDUAL',
            'H' => 'HUF',
            'T' => 'TRUST',
            'G' => 'GOVERNMENT',
            default => 'OTHER',
        };
    }
}
