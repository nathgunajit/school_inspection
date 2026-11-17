<?php

namespace App\Questionnaire;

/**
 * The predefined Phase 1 questionnaire (PRD 8.3).
 *
 * Question codes are permanent identifiers: never rename or reuse them.
 * Phase 2 will load this definition as "Version 1" of the configurable questionnaire.
 *
 * Question keys:
 *  code, label, type (radio|multi|select|text|textarea|number|date),
 *  options [value => label], required (bool),
 *  show_if [code, [values]], required_if [code, [values]], required_unless [code, [values]],
 *  remarks_if [values]  : remarks mandatory when answer is one of these
 *  photo  'required' | 'optional' | ['required_if' => [values]]
 *  photo_category        : category code for question-linked photos (default 'other')
 */
class QuestionnaireDefinition
{
    public const VERSION = 1;

    private const GSU = ['good' => 'Good', 'satisfactory' => 'Satisfactory', 'unsatisfactory' => 'Unsatisfactory'];
    private const YN = ['yes' => 'Yes', 'no' => 'No'];
    private const CONDITION = ['good' => 'Good', 'satisfactory' => 'Satisfactory', 'unsatisfactory' => 'Unsatisfactory', 'not_available' => 'Not available'];
    private const RATING4 = ['very_good' => 'Very good', 'good' => 'Good', 'needs_improvement' => 'Needs improvement', 'poor' => 'Poor'];
    private const RECEIVED = ['all' => 'All students', 'some' => 'Some students', 'not_received' => 'Not received'];
    private const HELD = ['monthly' => 'Held every month', 'irregular' => 'Irregular', 'not_held' => 'Not held'];

    public static function steps(): array
    {
        return [
            1 => ['key' => 'basic', 'title' => 'Basic Information', 'short' => 'Basic', 'icon' => 'bi-info-circle'],
            2 => ['key' => 'governance', 'title' => 'School Governance', 'short' => 'Governance', 'icon' => 'bi-diagram-3'],
            3 => ['key' => 'condition', 'title' => 'Condition of the School', 'short' => 'Condition', 'icon' => 'bi-building'],
            4 => ['key' => 'attendance', 'title' => 'Teacher & Student Attendance', 'short' => 'Attendance', 'icon' => 'bi-people'],
            5 => ['key' => 'classroom', 'title' => 'Classroom Observation & Fund Position', 'short' => 'Classroom', 'icon' => 'bi-easel'],
            6 => ['key' => 'evidence', 'title' => 'Photographs, Suggestions & Remarks', 'short' => 'Photos', 'icon' => 'bi-camera'],
        ];
    }

    /** @return array<int, array<int, array>> step => questions */
    public static function questions(): array
    {
        static $cache;

        return $cache ??= [
            1 => [
                ['code' => 'BAS_PERSON_NAME', 'label' => 'Name of the person met at the school', 'type' => 'text', 'required' => true],
                ['code' => 'BAS_PERSON_DESIG', 'label' => 'Designation of the person met', 'type' => 'text', 'required' => true],
            ],

            2 => [
                ['code' => 'GOV_01', 'label' => 'Method of Teaching', 'type' => 'multi', 'required' => true,
                    'options' => ['lecture' => 'Lecture', 'activity' => 'Activity-based', 'group' => 'Group work', 'tlm' => 'Use of TLM', 'digital' => 'Digital']],
                ['code' => 'GOV_02', 'label' => 'SMDC/SMC Formed', 'type' => 'radio', 'required' => true, 'options' => self::YN],
                ['code' => 'GOV_03', 'label' => 'Reason for Not Forming SMDC/SMC', 'type' => 'textarea',
                    'show_if' => ['GOV_02', ['no']], 'required_if' => ['GOV_02', ['no']]],
                ['code' => 'GOV_04', 'label' => 'Monthly Sitting of SMC/SMDC', 'type' => 'radio', 'options' => self::HELD,
                    'show_if' => ['GOV_02', ['yes']], 'required_if' => ['GOV_02', ['yes']], 'photo' => 'optional', 'photo_category' => 'records'],
                ['code' => 'GOV_05', 'label' => 'Morning Assembly', 'type' => 'radio', 'required' => true,
                    'options' => ['daily' => 'Held daily', 'irregular' => 'Irregular', 'not_held' => 'Not held']],
                ['code' => 'GOV_06', 'label' => 'Free Textbook Received', 'type' => 'radio', 'required' => true, 'options' => self::RECEIVED],
                ['code' => 'GOV_07', 'label' => 'Reason for Not Receiving Free Textbooks', 'type' => 'textarea',
                    'show_if' => ['GOV_06', ['some', 'not_received']], 'required_unless' => ['GOV_06', ['all']]],
                ['code' => 'GOV_08', 'label' => 'Free Uniform Received', 'type' => 'radio', 'required' => true, 'options' => self::RECEIVED],
                ['code' => 'GOV_09', 'label' => 'Reason for Not Receiving Free Uniform', 'type' => 'textarea',
                    'show_if' => ['GOV_08', ['some', 'not_received']], 'required_unless' => ['GOV_08', ['all']]],
                ['code' => 'GOV_10', 'label' => 'School Cleanliness', 'type' => 'radio', 'required' => true, 'options' => self::GSU,
                    'photo' => ['required_if' => ['unsatisfactory']]],
                ['code' => 'GOV_11', 'label' => 'Organic Kitchen Garden', 'type' => 'radio', 'required' => true,
                    'options' => ['yes' => 'Yes', 'no' => 'No', 'no_space' => 'No space'], 'photo' => 'optional'],
                ['code' => 'GOV_12', 'label' => 'Mid-Day Meal on Regular Basis', 'type' => 'radio', 'required' => true, 'options' => self::YN],
                ['code' => 'GOV_13', 'label' => 'Reason for Irregularity or Non-Availability of MDM', 'type' => 'textarea',
                    'show_if' => ['GOV_12', ['no']], 'required_if' => ['GOV_12', ['no']]],
                ['code' => 'GOV_14', 'label' => 'Separate and Hygienic Kitchen Available', 'type' => 'radio', 'required' => true, 'options' => self::YN,
                    'photo' => 'required', 'photo_category' => 'kitchen_mdm'],
                ['code' => 'GOV_15', 'label' => 'Hygienic Uniform Worn by Cook-cum-Helper', 'type' => 'radio', 'required' => true,
                    'options' => ['yes' => 'Yes', 'no' => 'No', 'cook_absent' => 'Cook absent']],
                ['code' => 'GOV_16', 'label' => 'Regular Health Check-up of Students', 'type' => 'radio', 'required' => true, 'options' => self::YN],
                ['code' => 'GOV_16_DATE', 'label' => 'Date of last health check-up', 'type' => 'date',
                    'show_if' => ['GOV_16', ['yes']], 'required_if' => ['GOV_16', ['yes']]],
                ['code' => 'GOV_17', 'label' => 'Health Check-up of Cook-cum-Helper', 'type' => 'radio', 'required' => true, 'options' => self::YN],
                ['code' => 'GOV_18', 'label' => 'Fire Extinguisher Available', 'type' => 'radio', 'required' => true,
                    'options' => ['valid' => 'Available and valid', 'expired' => 'Expired', 'not_available' => 'Not available'],
                    'photo' => ['required_if' => ['valid', 'expired']]],
                ['code' => 'GOV_19', 'label' => 'Availability of Sports Items', 'type' => 'radio', 'required' => true,
                    'options' => ['adequate' => 'Adequate', 'inadequate' => 'Inadequate', 'not_available' => 'Not available']],
                ['code' => 'GOV_20', 'label' => 'School Record-Keeping System', 'type' => 'radio', 'required' => true, 'options' => self::GSU,
                    'remarks_if' => ['unsatisfactory']],
                ['code' => 'GOV_21', 'label' => 'Administration', 'type' => 'radio', 'required' => true, 'options' => self::GSU,
                    'remarks_if' => ['unsatisfactory']],
                ['code' => 'GOV_22', 'label' => 'Attendance Registers up to date', 'type' => 'radio', 'required' => true,
                    'options' => ['yes' => 'Yes', 'partly' => 'Partly', 'no' => 'No']],
                ['code' => 'GOV_23', 'label' => 'Accounting System', 'type' => 'radio', 'required' => true, 'options' => self::GSU,
                    'remarks_if' => ['unsatisfactory']],
                ['code' => 'GOV_24', 'label' => 'Teaching Learning Material (TLM)', 'type' => 'radio', 'required' => true,
                    'options' => ['in_use' => 'Available and in use', 'not_in_use' => 'Available, not in use', 'not_available' => 'Not available']],
            ],

            3 => collect([
                'CON_01' => ['School Building', 'other'],
                'CON_02' => ['Classrooms', 'classroom'],
                'CON_03' => ['Boundary Wall', 'other'],
                'CON_04' => ['Drinking Water', 'drinking_water'],
                'CON_05' => ["Boys' Toilet", 'toilet'],
                'CON_06' => ["Girls' Toilet", 'toilet'],
                'CON_07' => ['Electricity and Fans', 'other'],
                'CON_08' => ['Furniture', 'other'],
                'CON_09' => ['Playground', 'playground'],
                'CON_10' => ['Kitchen and Dining Facilities', 'kitchen_mdm'],
                'CON_11' => ['Ramp and Accessibility', 'other'],
            ])->map(fn ($v, $code) => [
                'code' => $code, 'label' => $v[0], 'type' => 'radio', 'required' => true, 'options' => self::CONDITION,
                'remarks_if' => ['unsatisfactory', 'not_available'],
                'photo' => ['required_if' => ['unsatisfactory', 'not_available']],
                'photo_category' => $v[1],
            ])->values()->all(),

            4 => [
                ['code' => 'ATT_SANCTIONED', 'label' => 'Total Sanctioned Teachers', 'type' => 'number', 'required' => true],
                ['code' => 'ATT_WORKING', 'label' => 'Total Working Teachers', 'type' => 'number', 'required' => true],
                ['code' => 'ATT_PRESENT', 'label' => 'Teachers Present', 'type' => 'number', 'required' => true],
                ['code' => 'ATT_ABSENT', 'label' => 'Teachers Absent', 'type' => 'number', 'required' => true],
                ['code' => 'ATT_ENROLLED', 'label' => 'Total Students Enrolled', 'type' => 'number', 'required' => true],
                ['code' => 'ATT_STUDENTS_PRESENT', 'label' => 'Students Present Today (head count)', 'type' => 'number', 'required' => true],
                ['code' => 'ATT_REMARKS', 'label' => 'Inspection Remarks', 'type' => 'textarea'],
            ],

            5 => [
                ['code' => 'CLS_CLASS', 'label' => 'Class Observed', 'type' => 'select', 'required' => true,
                    'options' => collect(range(1, 12))->mapWithKeys(fn ($c) => [(string) $c => 'Class '.$c])->prepend('Pre-primary', 'pre_primary')->all()],
                ['code' => 'CLS_SUBJECT', 'label' => 'Subject', 'type' => 'text', 'required' => true],
                ['code' => 'CLS_TEACHER', 'label' => 'Teacher', 'type' => 'text', 'required' => true],
                ['code' => 'CLS_ACTIVITY', 'label' => 'Teaching-learning activities', 'type' => 'radio', 'required' => true, 'options' => self::RATING4],
                ['code' => 'CLS_PARTICIPATION', 'label' => 'Student participation', 'type' => 'radio', 'required' => true, 'options' => self::RATING4],
                ['code' => 'CLS_TLM_USE', 'label' => 'Use of TLM', 'type' => 'radio', 'required' => true, 'options' => self::RATING4],
                ['code' => 'CLS_NOTES', 'label' => 'Observation notes', 'type' => 'textarea'],
                ['code' => 'CLS_SUGGESTIONS', 'label' => 'Suggestions for the teacher', 'type' => 'textarea'],
            ],

            6 => [
                ['code' => 'FIN_GENERAL_REMARKS', 'label' => 'General remarks', 'type' => 'textarea'],
            ],
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
            $vals = (array) $value;

            return collect($vals)->map(fn ($v) => $q['options'][$v] ?? $v)->implode(', ');
        }

        return is_array($value) ? implode(', ', $value) : (string) $value;
    }
}
