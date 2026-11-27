<?php

namespace Database\Seeders;

use App\Enums\UserLevel;
use App\Models\Notice;
use App\Models\NoticeRead;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\JurisdictionService;
use App\Services\NoticeService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * 25-11-2026: demo Notices & Orders (V14 item 1) for training, sent by the State Administrator and the demo
 * District / Block officers (DemoUserSeeder). Re-runnable (matched by ref_no); never runs in production.
 */
class DemoNoticeSeeder extends Seeder
{
    public const ATTACHMENT_REF = 'SSA/INSP/2026/101';

    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->warn('DemoNoticeSeeder skipped in production.');

            return;
        }

        $admin = User::where('level', UserLevel::StateAdmin->value)->orderBy('id')->first();
        $byMobile = fn (string $m) => User::where('mobile', $m)->first();
        $j = app(JurisdictionService::class);
        $area = function (?User $u, string $level) use ($j): array {
            $cur = $u ? $j->current($u) : null;

            return match ($level) {
                'district' => ['district_id' => $cur?->district_id],
                'block' => ['district_id' => $cur?->district_id, 'block_id' => $cur?->block_id],
                default => [],
            };
        };
        $all = ['state', 'district', 'block', 'cluster'];
        $day = fn (int $ago) => today()->subDays($ago)->toDateString();

        $kokrajhar = $byMobile('9000000011');
        $dhubri = $byMobile('9000000013');
        $dotma = $byMobile('9000000021');

        $notices = [
            [$admin, ['type' => 'order', 'ref_no' => self::ATTACHMENT_REF, 'title' => 'Monthly school inspection drive, December 2026',
                'body' => "All inspecting officers shall complete at least the number of school inspections fixed for their level during December 2026.\n\nInspections are to be filled in the School Inspection application with geo-tagged photographs from the in-app camera, and submitted on the same day. The attached order gives the targets and the reporting schedule.",
                'notice_date' => $day(1), 'levels' => $all], [], true],
            [$admin, ['type' => 'instruction', 'ref_no' => 'SSA/INSP/2026/098', 'title' => 'Use the in-app camera for geo-tagged photographs',
                'body' => "Photographs of the school building, classrooms, toilets and drinking water facility must be taken with the in-app camera, so that the location and time are stamped on them.\n\nGallery photos are accepted only when the camera cannot be used, and are marked \"From gallery\" in the report.",
                'notice_date' => $day(4), 'levels' => ['district', 'block', 'cluster'], 'show_on_home' => false], [], false],
            [$admin, ['type' => 'notification', 'ref_no' => 'SSA/GEN/2026/045', 'title' => 'Holiday list 2026 published in the Holiday Calendar',
                'body' => "The Government of Assam holiday list for 2026, with restricted holidays and the half holiday, is now available in the Holiday Calendar.\n\nNo inspection can be planned on a gazetted holiday, and Today's Schools shows no list on those days.",
                'notice_date' => $day(6), 'expires_on' => today()->addDays(30)->toDateString(), 'levels' => $all], [], false],
            [$kokrajhar, ['type' => 'instruction', 'ref_no' => 'DPO/KJR/2026/312', 'title' => 'Complete pending inspection drafts before the 5th',
                'body' => "Block and Cluster officers of the district are requested to complete and submit every inspection still in draft before the 5th of next month.\n\nDrafts not submitted are cancelled automatically after the draft expiry period.",
                'notice_date' => $day(2), 'levels' => ['block', 'cluster']], 'district', false],
            [$dhubri, ['type' => 'order', 'ref_no' => 'DPO/DHB/2026/207', 'title' => 'Visit flood-affected schools this week',
                'body' => "Cluster officers shall visit the schools of their cluster affected by the recent flood within this week and record the condition of the building, toilets and drinking water in the inspection form.\n\nSchools that cannot be reached are to be marked \"Not visited\" on Today's Schools with the reason.",
                'notice_date' => $day(1), 'expires_on' => today()->addDays(7)->toDateString(), 'levels' => ['cluster']], 'district', false],
            [$dotma, ['type' => 'notification', 'ref_no' => 'BEEO/DTM/2026/054', 'title' => 'Cluster review meeting on Friday',
                'body' => "A review meeting of all Cluster Resource Centre Coordinators of the block will be held at the Block office on Friday at 11:00 AM.\n\nPlease bring the list of schools inspected this month and the open issues found.",
                'notice_date' => $day(0), 'levels' => ['cluster']], 'block', false],
            // 25-11-2026: the new types (Notice Types master)
            [$admin, ['type' => 'publication', 'ref_no' => 'SSA/PUB/2026/011', 'title' => 'School Inspection handbook for officers, 2026',
                'body' => "The handbook explains each step of the inspection form, the photographs to take and how the rating is worked out.\n\nPlease read it before the next inspection drive and share it with the officers of your area.",
                'notice_date' => $day(3), 'levels' => $all], [], false],
            [$kokrajhar, ['type' => 'meeting_minutes', 'ref_no' => 'DPO/KJR/2026/MM-07', 'title' => 'Minutes of the district review meeting',
                'body' => "1. Every Block shall finish the pending inspections of the month by the 25th.\n2. Cluster officers shall use the in-app camera for all photographs.\n3. The next review meeting will be held in the first week of next month.",
                'notice_date' => $day(5), 'levels' => ['block', 'cluster']], 'district', false],
            [$admin, ['type' => 'notification', 'ref_no' => 'SSA/GEN/2026/039', 'title' => 'Training on the School Inspection application (completed)',
                'body' => 'The online training on the School Inspection application for all officers was held as scheduled. Recordings are available with the district office.',
                'notice_date' => $day(10), 'expires_on' => $day(1), 'levels' => $all], [], false],
            [$admin, ['type' => 'instruction', 'ref_no' => 'SSA/INSP/2026/090', 'title' => 'Inspection format for the second quarter (withdrawn)',
                'body' => 'This instruction was issued by mistake and has been withdrawn. Continue to use the current inspection form.',
                'notice_date' => $day(8), 'levels' => $all, 'status' => 'withdrawn', 'withdrawn_at' => now()->subDays(7)], [], false],
        ];

        $created = 0;
        foreach ($notices as [$sender, $data, $scope, $withFile]) {
            if (! $sender) {
                continue; // the demo officer is not there (e.g. a different Location Master)
            }
            $data += ['status' => 'published', 'created_by' => $sender->id, 'district_id' => null, 'block_id' => null, 'cluster_id' => null];
            if ($scope) {
                $data = $area($sender, $scope) + $data;
            }
            $notice = Notice::updateOrCreate(['ref_no' => $data['ref_no']], $data);
            if ($notice->wasRecentlyCreated) {
                $created++;
                if ($withFile) {
                    $notice->update($this->attachment($notice));
                }
                AuditLogger::log('notice_published', $notice, ['type' => $notice->type, 'title' => $notice->title, 'audience' => $notice->audienceLabel(), 'demo' => true], $sender);
            }
        }

        // a few reads and acknowledgements, so the read report has data
        $this->reads(Notice::where('ref_no', self::ATTACHMENT_REF)->first(), 2, true);
        $this->reads(Notice::where('ref_no', 'DPO/KJR/2026/312')->first(), 1, false);
        NoticeService::flushCounts();

        $this->command?->info("Demo notices: $created created, ".Notice::count().' in total.');
    }

    /** A one-page order letter (DomPDF) on the private disk, where NoticeService keeps attachments. */
    private function attachment(Notice $n): array
    {
        $html = '<html><body style="font-family: DejaVu Sans, sans-serif; font-size: 11pt; color: #1e293b">'
            .'<div style="text-align:center; border-bottom: 2px solid #1565c0; padding-bottom: 8px">'
            .'<div style="font-size: 13pt; font-weight: bold">Government of Assam</div>'
            .'<div style="font-size: 12pt; color: #1565c0; font-weight: bold">Samagra Shiksha Axom</div>'
            .'<div style="font-size: 9pt; color: #64748b">Kahilipara, Guwahati-19 (demo document)</div></div>'
            .'<table style="width:100%; margin-top: 12px; font-size: 10pt"><tr><td>No. '.e($n->ref_no).'</td><td style="text-align:right">Dated '.e(date('d-m-Y', strtotime($n->notice_date))).'</td></tr></table>'
            .'<h3 style="text-align:center; margin: 18px 0 10px">ORDER</h3>'
            .'<p style="font-weight:bold">Subject: '.e($n->title).'</p>'
            .collect(explode("\n\n", $n->body))->map(fn ($p) => '<p style="text-align:justify; line-height: 1.5">'.e($p).'</p>')->implode('')
            .'<p style="text-align:justify; line-height: 1.5">Targets: State and District officers 4, Block officers 8 and Cluster officers 15 school inspections in the month. District offices shall review progress every Monday.</p>'
            .'<div style="margin-top: 50px; text-align:right">Sd/-<br><b>Mission Director</b><br>Samagra Shiksha Axom</div>'
            .'</body></html>';
        $path = 'notices/'.Str::random(40).'.pdf';
        Storage::disk(NoticeService::DISK)->put($path, Pdf::loadHTML($html)->setPaper('a4')->output());

        return ['attachment_path' => $path, 'attachment_name' => 'Order-'.str_replace('/', '-', $n->ref_no).'.pdf', 'attachment_mime' => 'application/pdf'];
    }

    private function reads(?Notice $notice, int $count, bool $ack): void
    {
        if (! $notice) {
            return;
        }
        foreach ($notice->recipients()->where('users.id', '!=', $notice->created_by)->orderBy('users.id')->take($count)->get(['users.id']) as $i => $u) {
            NoticeRead::firstOrCreate(['notice_id' => $notice->id, 'user_id' => $u->id],
                ['read_at' => now()->subHours(20 - $i), 'acknowledged_at' => $ack ? now()->subHours(19 - $i) : null]);
        }
    }
}
