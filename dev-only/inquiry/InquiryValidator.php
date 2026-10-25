<?php
declare(strict_types=1);
/** Development-only server-side field validator. No HTTP handler, I/O or mail. */
final class InquiryValidator {
    private const LIMITS = [
        'person_name'=>50, 'organization_name'=>120, 'email'=>254,
        'phone'=>20, 'message'=>2000, 'budget'=>60, 'desired_deadline'=>60
    ];
    private const CHOICES = [
        'inquiry_type'=>['estimate','consult','other'],
        'client_type'=>['person','corporate','group'],
        'service'=>['web','app','support','other']
    ];
    public static function normalize(array $input): array {
        $out=[];
        foreach (array_merge(array_keys(self::CHOICES), array_keys(self::LIMITS)) as $field) {
            if (isset($input[$field]) && !is_string($input[$field])) {
                throw new InvalidArgumentException('Invalid field type: '.$field);
            }
            $out[$field] = trim($input[$field] ?? '');
        }
        foreach (['inquiry_type','client_type','person_name','email','service','message'] as $field) {
            if ($out[$field] === '') throw new InvalidArgumentException('Required field: '.$field);
        }
        if (in_array($out['client_type'],['corporate','group'],true) && $out['organization_name']==='') {
            throw new InvalidArgumentException('Organization required');
        }
        foreach (self::CHOICES as $field=>$choices) {
            if (!in_array($out[$field],$choices,true)) throw new InvalidArgumentException('Invalid choice: '.$field);
        }
        foreach (self::LIMITS as $field=>$limit) {
            if (mb_strlen($out[$field],'UTF-8') > $limit) throw new InvalidArgumentException('Too long: '.$field);
        }
        if (!filter_var($out['email'],FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Invalid email');
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', implode('', $out))) {
            throw new InvalidArgumentException('Forbidden control character');
        }
        return $out;
    }
}
