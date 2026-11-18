<?php

namespace App\Questionnaire;

/**
 * The predefined inspection questionnaire, Version 2
 * (source: Documents/Modification of the ApplicationV1.docx; supersedes the PRD 8.3 v1 form).
 *
 * Question codes are permanent identifiers: never rename or reuse them.
 * v1 codes (GOV_*, CON_*, ATT_*, CLS_*, FIN_*) belong to legacy inspections and must not be reused.
 *
 * Question keys:
 *  code, label, type (radio|multi|select|text|textarea|number|date),
 *  options [value => label], required (bool),
 *  show_if [code, [values]], enable_if [code, [values]] (shown but disabled otherwise), required_if [code, [values]], required_unless [code, [values]],
 *  remarks_if [values], photo 'required'|'optional'|['required_if' => [values]], photo_category
 *
 * Steps 3–5 and 7 are repeating sections / evidence handled outside the question list.
 */
class QuestionnaireDefinition
{
    public const VERSION = 2;

    private const YN = ['yes' => 'Yes', 'no' => 'No'];
    private const SU = ['satisfactory' => 'Satisfactory', 'unsatisfactory' => 'Unsatisfactory'];

    public static function steps(): array
    {
        return [
            1 => ['key' => 'condition', 'title' => 'Condition of the School', 'short' => 'Condition', 'tab' => 'School Condition', 'icon' => 'bi-building'],
            2 => ['key' => 'strength', 'title' => "Teacher's & Student's Information", 'short' => 'Strength', 'tab' => 'Students & Teachers', 'icon' => 'bi-people'],
            3 => ['key' => 'absentee', 'title' => 'Teachers Absentee Form', 'short' => 'Absentee', 'tab' => 'Absent Teachers', 'icon' => 'bi-person-x'],
            4 => ['key' => 'classroom', 'title' => 'Class Room Performance', 'short' => 'Classroom', 'tab' => 'Classroom Observation', 'icon' => 'bi-easel'],
            5 => ['key' => 'fund', 'title' => 'Fund Received & Expenditure', 'short' => 'Fund', 'tab' => 'Fund Details', 'icon' => 'bi-cash-coin'],
            6 => ['key' => 'governance', 'title' => 'School Governance', 'short' => 'Governance', 'tab' => 'School Governance', 'icon' => 'bi-diagram-3'],
            7 => ['key' => 'photos', 'title' => 'Photographs of School', 'short' => 'Photos', 'tab' => 'Photographs', 'icon' => 'bi-camera'],
        ];
    }

    /** @return array<int, array<int, array>> step => questions */
    public static function questions(): array
    {
        static $cache;
        if ($cache) {
            return $cache;
        }

        $yn = fn (string $code, string $label, array $extra = []) => ['code' => $code, 'label' => $label, 'type' => 'radio', 'required' => true, 'options' => self::YN] + $extra;
        $su = fn (string $code, string $label) => ['code' => $code, 'label' => $label, 'type' => 'radio', 'required' => true, 'options' => self::SU];
        $num = fn (string $code, string $label) => ['code' => $code, 'label' => $label, 'type' => 'number', 'required' => true];
        $reason = fn (string $code, string $label, string $parent) => ['code' => $code, 'label' => $label, 'type' => 'textarea',
            'show_if' => [$parent, ['no']], 'required_if' => [$parent, ['no']]];

        return $cache = [
            1 => [
                ['code' => 'BAS_PERSON_NAME', 'label' => 'Name of the person met at the school', 'type' => 'text', 'required' => true],
                ['code' => 'BAS_PERSON_DESIG', 'label' => 'Designation of the person met', 'type' => 'text', 'required' => true],
                ['code' => 'CND_01', 'label' => 'Type of School Building', 'type' => 'radio', 'required' => true,
                    'options' => ['pucca' => 'Pucca', 'semi_pucca' => 'Semi Pucca', 'others' => 'Others']],
                $yn('CND_02', 'Boundary Wall'),
                $yn('CND_03', 'Functional Toilet for Girls'),
                $yn('CND_04', 'Running Water Facility in Girls Toilet'),
                $yn('CND_05', 'Functional Toilet for Boys'),
                $yn('CND_06', 'Running Water Facility in Boys Toilet'),
                $yn('CND_07', 'Safe Drinking Water Facility'),
                $yn('CND_08', 'Electricity'),
                $yn('CND_09', 'External Electrification'),
                $yn('CND_10', 'Internal Electrification'),
                $yn('CND_11', 'Adequate Number of Classrooms'),
                $yn('CND_12', 'Requirement of ACR (Additional Classroom)'),
                $yn('CND_13', 'Land Availability'),
                $yn('CND_14', 'Classrooms having Black Board'),
                $yn('CND_15', 'School Sign Board'),
                $yn('CND_16', 'Does the School have Ramp with Rails'),
                $yn('CND_17', 'Internet Facility'),
                $yn('CND_18', 'Internet is Functional', ['enable_if' => ['CND_17', ['yes']], 'required' => false, 'required_if' => ['CND_17', ['yes']]]),
                $yn('CND_19', 'ICT Lab'),
                $yn('CND_20', 'ICT Lab is Functional', ['enable_if' => ['CND_19', ['yes']], 'required' => false, 'required_if' => ['CND_19', ['yes']]]),
                $yn('CND_21', 'Vocational Education'),
                $yn('CND_22', 'Smart Classroom'),
                $yn('CND_23', 'Tele Education'),
                $yn('CND_24', 'Does the School have Tinkering Lab'),
                ['code' => 'CND_25', 'label' => 'Implementing Agency of Tinkering Lab', 'type' => 'radio',
                    'options' => ['niti_aayog' => 'NITI Aayog', 'samagra_shiksha' => 'Samagra Shiksha', 'na' => 'Not Applicable'],
                    'show_if' => ['CND_24', ['yes']], 'required_if' => ['CND_24', ['yes']]],
                ['code' => 'CND_26', 'label' => 'Tinkering Lab is Functional', 'type' => 'radio',
                    'options' => ['yes' => 'Yes', 'no' => 'No', 'na' => 'Not Applicable'],
                    'show_if' => ['CND_24', ['yes']], 'required_if' => ['CND_24', ['yes']]],
            ],

            2 => [
                $num('TSI_01', 'Number of Students Present'),
                $num('TSI_02', 'Number of Students Absent'),
                $num('TSI_03', 'Seating Arrangement of the Students (number of benches/desks)'),
                $num('TSI_04', 'Number of Regular Teachers'),
                $num('TSI_05', 'Number of Contractual Teachers'),
                $num('TSI_06', 'Number of SSA Teachers'),
                $num('TSI_07', 'Total Number of Provincialised Tutors'),
                $num('TSI_08', 'Total Number of Tutors Newly Accorded'),
                $num('TSI_09', 'Number of Tutors/Teachers Present on the day of Inspection'),
                $num('TSI_10', 'Number of Male Trained Teachers'),
                $num('TSI_11', 'Number of Male Untrained Teachers'),
                $num('TSI_12', 'Number of Female Trained Teachers'),
                $num('TSI_13', 'Number of Female Untrained Teachers'),
            ],

            3 => [],
            4 => [],
            5 => [],

            6 => [
                ['code' => 'GOVR_01', 'label' => 'Method of Teaching', 'type' => 'radio', 'required' => true,
                    'options' => ['conventional' => 'Conventional', 'non_conventional' => 'Non-Conventional']],
                $yn('GOVR_02', 'SMDC/SMC Formed'),
                $reason('GOVR_03', 'Reason for not forming SMDC/SMC', 'GOVR_02'),
                $yn('GOVR_04', 'Monthly Sitting of SMC/SMDC', ['show_if' => ['GOVR_02', ['yes']], 'required' => false, 'required_if' => ['GOVR_02', ['yes']]]),
                $yn('GOVR_05', 'Morning Assembly'),
                $yn('GOVR_06', 'Free Text Book Received'),
                $reason('GOVR_07', 'Reason for not receiving Free Text Books', 'GOVR_06'),
                $yn('GOVR_08', 'Free Uniform Received'),
                $reason('GOVR_09', 'Reason for not receiving Free Uniform', 'GOVR_08'),
                $su('GOVR_10', 'School Cleanliness'),
                $yn('GOVR_11', 'Organic Kitchen Garden'),
                $yn('GOVR_12', 'Mid-Day Meal on regular basis'),
                $reason('GOVR_13', 'If irregular or not provided, state the reason with due specification', 'GOVR_12'),
                $yn('GOVR_14', 'Separate & Hygienic Kitchen is available'),
                $yn('GOVR_15', 'Hygienic uniform worn by the Cook-cum-Helper'),
                $yn('GOVR_16', 'Regular Health Check-up of Students'),
                $yn('GOVR_17', 'Health Check-up of Cook-cum-Helper'),
                $yn('GOVR_18', 'Fire Extinguisher is available'),
                $yn('GOVR_19', 'Availability of Sports Items'),
                $su('GOVR_20', 'Record Keeping System of the School'),
                $su('GOVR_21', 'Administration'),
                $su('GOVR_22', 'Attendance'),
                $su('GOVR_23', 'Accounting System'),
                $su('GOVR_24', 'TLM'),
            ],

            7 => [],
        ];
    }

    /** Flat map code => question (with 'step'). */
    public static function all(): array
    {
        static $flat;
        if ($flat) {
            return $flat;
        }
        $flat = [];
        foreach (self::questions() as $step => $qs) {
            foreach ($qs as $q) {
                $flat[$q['code']] = $q + ['step' => $step];
            }
        }

        return $flat;
    }

    public static function find(string $code): ?array
    {
        return self::all()[$code] ?? null;
    }

    public static function optionLabel(string $code, mixed $value): string
    {
        $q = self::find($code);
        if ($value === null || $value === '' || $value === []) {
            return '—';
        }
        if (! empty($q['options'])) {
            return collect((array) $value)->map(fn ($v) => $q['options'][$v] ?? $v)->implode(', ');
        }

        return is_array($value) ? implode(', ', $value) : (string) $value;
    }

    /** Teaching staff total from step 2 (regular + contractual + SSA + tutors). */
    public static function totalTeachers(array $answers): ?int
    {
        $codes = ['TSI_04', 'TSI_05', 'TSI_06', 'TSI_07', 'TSI_08'];
        $vals = array_map(fn ($c) => $answers[$c] ?? null, $codes);
        if (in_array(null, $vals, true)) {
            return null;
        }

        return array_sum(array_map('intval', $vals));
    }

    /** Total students = present (TSI_01) + absent (TSI_02); null until both are entered. */
    public static function totalStudents(array $answers): ?int
    {
        $p = $answers['TSI_01'] ?? null;
        $a = $answers['TSI_02'] ?? null;

        return ($p === null || $p === '' || $a === null || $a === '') ? null : (int) $p + (int) $a;
    }

    /** Colour tone of a choice answer: tone-good / tone-mid / tone-bad (presentation only). */
    public static function tone(mixed $value): string
    {
        return match (true) {
            in_array($value, ['unsatisfactory', 'not_available', 'not_received', 'not_held', 'no', 'poor', 'expired', 'inadequate'], true) => 'tone-bad',
            in_array($value, ['satisfactory', 'some', 'irregular', 'partly', 'needs_improvement', 'not_in_use', 'cook_absent'], true) => 'tone-mid',
            in_array($value, ['good', 'very_good', 'yes', 'all', 'monthly', 'daily', 'valid', 'adequate', 'in_use'], true) => 'tone-good',
            default => '',
        };
    }
}
