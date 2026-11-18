<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Support\ReturnPolicy;
use Illuminate\Http\Request;
use App\Traits\ActivityLogger;

class SettingController extends Controller
{
    use ActivityLogger;

    const DEFAULT_MONTHLY_REQUEST_LIMIT = 5;
    const DEFAULT_STUDENT_ID_LENGTH = 15;
    const DEFAULT_MAX_ITEMS_PER_REQUEST = 10;

    // Roles that have a request-size limit (the AMO is exempt).
    const MAX_ITEMS_ROLES = ['department_head', 'academic_coordinator', 'admin', 'instructor', 'cashier'];

    // Most different items one request may hold for a role (the AMO can change it per role).
    public static function maxItemsPerRequest(?string $role = null): int
    {
        $role = strtolower((string) $role);
        if (in_array($role, self::MAX_ITEMS_ROLES, true)) {
            $own = Setting::where('setting_key', "max_items_{$role}")->first();
            if ($own && (int) $own->setting_value >= 1) {
                return (int) $own->setting_value;
            }
        }
        $s = Setting::where('setting_key', 'max_items_per_request')->first();
        $n = $s ? (int) $s->setting_value : self::DEFAULT_MAX_ITEMS_PER_REQUEST;
        return $n >= 1 ? $n : self::DEFAULT_MAX_ITEMS_PER_REQUEST;
    }

    // Monthly request limit for a role (own value, else the old single limit, else the default).
    public static function monthlyLimitFor(?string $role = null): int
    {
        $role = strtolower((string) $role);
        if (in_array($role, self::MAX_ITEMS_ROLES, true)) {
            $own = Setting::where('setting_key', "monthly_limit_{$role}")->first();
            if ($own && (int) $own->setting_value >= 1) {
                return (int) $own->setting_value;
            }
        }
        $s = Setting::where('setting_key', 'monthly_request_limit')->first();
        $n = $s ? (int) $s->setting_value : self::DEFAULT_MONTHLY_REQUEST_LIMIT;
        return $n >= 1 ? $n : self::DEFAULT_MONTHLY_REQUEST_LIMIT;
    }

    // Any authenticated role: its own items-per-request limit (the Request page needs it).
    public function maxItems()
    {
        return response()->json(['max_items_per_request' => self::maxItemsPerRequest(request()->user()->role)]);
    }

    /* ---- Allowed sign-up email domains (empty list = any email is accepted) ---- */

    public static function allowedEmailDomains(): array
    {
        $raw = (string) optional(Setting::where('setting_key', 'allowed_email_domains')->first())->setting_value;
        return array_values(array_filter(array_map('trim', explode(',', strtolower($raw)))));
    }

    // Public (the sign-up page shows it as a hint).
    public function publicEmailDomains()
    {
        return response()->json(['domains' => self::allowedEmailDomains()]);
    }

    // AMO only (route middleware).
    public function emailDomains()
    {
        return response()->json(['domains' => self::allowedEmailDomains()]);
    }

    // AMO only (route middleware).
    public function updateEmailDomains(Request $request)
    {
        $v = $request->validate(['domains' => 'present|string|max:500']);
        $list = array_values(array_unique(array_filter(array_map(
            fn ($d) => ltrim(strtolower(trim($d)), '@'),
            preg_split('/[\s,;]+/', $v['domains'])
        ))));
        foreach ($list as $d) {
            if (!preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/', $d)) {
                return response()->json(['message' => "\"{$d}\" is not a valid domain. Use the part after the @, for example school.edu.ph."], 422);
            }
        }
        Setting::updateOrCreate(['setting_key' => 'allowed_email_domains'], ['setting_value' => implode(',', $list)]);
        $this->logActivity(request()->user()->idUsers, $list ? 'Set allowed sign-up email domains: ' . implode(', ', $list) : 'Turned off the sign-up email domain restriction');
        return response()->json(['domains' => $list]);
    }

    // AMO only (route middleware): every role's two limits for the Settings card.
    public function requestLimits()
    {
        $by = [];
        foreach (self::MAX_ITEMS_ROLES as $r) {
            $by[$r] = ['items' => self::maxItemsPerRequest($r), 'monthly' => self::monthlyLimitFor($r)];
        }
        return response()->json(['by_role' => $by]);
    }

    // AMO only (route middleware).
    public function updateRequestLimits(Request $request)
    {
        $rules = ['by_role' => 'required|array'];
        foreach (self::MAX_ITEMS_ROLES as $r) {
            $rules["by_role.{$r}.items"] = 'required|integer|min:1|max:50';
            $rules["by_role.{$r}.monthly"] = 'required|integer|min:1|max:999';
        }
        $v = $request->validate($rules);

        foreach (self::MAX_ITEMS_ROLES as $r) {
            Setting::updateOrCreate(['setting_key' => "max_items_{$r}"], ['setting_value' => (string) $v['by_role'][$r]['items']]);
            Setting::updateOrCreate(['setting_key' => "monthly_limit_{$r}"], ['setting_value' => (string) $v['by_role'][$r]['monthly']]);
        }
        $this->logActivity(
            request()->user()->idUsers,
            'Set request limits (items per request / requests per month): ' . collect(self::MAX_ITEMS_ROLES)
                ->map(fn ($r) => "{$r} {$v['by_role'][$r]['items']}/{$v['by_role'][$r]['monthly']}")->implode(', ')
        );

        return $this->requestLimits();
    }

    // Longest student ID number the system accepts (the AMO can change it).
    public static function studentIdMax(): int
    {
        $s = Setting::where('setting_key', 'student_id_max_length')->first();
        $n = $s ? (int) $s->setting_value : self::DEFAULT_STUDENT_ID_LENGTH;
        return $n >= 4 ? $n : self::DEFAULT_STUDENT_ID_LENGTH;
    }

    // Returns an error message, or null when the ID is fine. Digits and '-' only.
    public static function studentIdError(string $id): ?string
    {
        $max = self::studentIdMax();
        if (!preg_match('/^[0-9-]+$/', $id)) {
            return 'The student ID can only have numbers and a dash (-).';
        }
        if (mb_strlen($id) > $max) {
            return "The student ID can be at most {$max} characters.";
        }
        return null;
    }

    // Any authenticated role can read this (the forms need it).
    public function studentIdLength()
    {
        return response()->json(['student_id_max_length' => self::studentIdMax()]);
    }

    // AMO only (route middleware).
    public function updateStudentIdLength(Request $request)
    {
        $validated = $request->validate([
            'student_id_max_length' => 'required|integer|min:4|max:30',
        ]);
        Setting::updateOrCreate(
            ['setting_key' => 'student_id_max_length'],
            ['setting_value' => (string) $validated['student_id_max_length']]
        );
        $this->logActivity(request()->user()->idUsers, "Set the student ID length limit to {$validated['student_id_max_length']}");

        return response()->json([
            'message' => 'Student ID length saved.',
            'student_id_max_length' => (int) $validated['student_id_max_length'],
        ]);
    }

    /* Readable by any role (non-AMO users need to see their own limit). */
    public function monthlyRequestLimit()
    {
        return response()->json([
            'monthly_request_limit' => self::monthlyLimitFor(request()->user()->role),
        ]);
    }

    /* AMO-set default stock levels for School Equipment and Type B items; they pre-fill the Add forms. */
    public const STOCK_GROUPS = [
        'school_equipment' => 'stock_levels_school_equipment',
        'type_b' => 'stock_levels_type_b',
    ];

    public static function stockLevelsFor(string $group): array
    {
        $row = Setting::where('setting_key', self::STOCK_GROUPS[$group])->first();
        $v = $row ? json_decode((string) $row->setting_value, true) : null;

        return [
            'critical' => (int) ($v['critical'] ?? \App\Models\Item::DEFAULT_CRITICAL),
            'minimum' => (int) ($v['minimum'] ?? \App\Models\Item::DEFAULT_MINIMUM),
            'optimal' => (int) ($v['optimal'] ?? \App\Models\Item::DEFAULT_OPTIMAL),
        ];
    }

    // The group an item belongs to, or null (consumables, uniforms, ...).
    public static function stockGroupFor(?string $category, ?string $itemType): ?string
    {
        if ($category === 'School Equipment') return 'school_equipment';
        if ($category === 'Office Supplies' && $itemType === \App\Models\Item::TYPE_B) return 'type_b';
        return null;
    }

    public function stockLevels()
    {
        return response()->json([
            'school_equipment' => self::stockLevelsFor('school_equipment'),
            'type_b' => self::stockLevelsFor('type_b'),
        ]);
    }

    public function updateStockLevels(Request $request)
    {
        $rules = ['apply_to_existing' => 'sometimes|boolean'];
        foreach (array_keys(self::STOCK_GROUPS) as $g) {
            foreach (['critical', 'minimum', 'optimal'] as $f) {
                $rules["$g.$f"] = 'required|integer|min:0|max:100000';
            }
        }
        $validated = $request->validate($rules);

        foreach (array_keys(self::STOCK_GROUPS) as $g) {
            $v = $validated[$g];
            if ($v['critical'] > $v['minimum'] || $v['minimum'] > $v['optimal']) {
                return response()->json([
                    'message' => 'Stock levels must satisfy: Critical <= Minimum <= Optimal.',
                ], 422);
            }
        }

        $updated = ['school_equipment' => 0, 'type_b' => 0];

        foreach (self::STOCK_GROUPS as $g => $key) {
            $v = $validated[$g];
            Setting::updateOrCreate(
                ['setting_key' => $key],
                ['setting_value' => json_encode([
                    'critical' => (int) $v['critical'],
                    'minimum' => (int) $v['minimum'],
                    'optimal' => (int) $v['optimal'],
                ])]
            );

            if (!empty($validated['apply_to_existing'])) {
                $query = $g === 'school_equipment'
                    ? \App\Models\Item::where('category', 'School Equipment')
                    : \App\Models\Item::where('category', 'Office Supplies')->where('item_type', \App\Models\Item::TYPE_B);
                $updated[$g] = $query->update([
                    'critical_stock' => (int) $v['critical'],
                    'minimum_stock' => (int) $v['minimum'],
                    'optimal_stock' => (int) $v['optimal'],
                ]);
            }
        }

        $this->logActivity(
            request()->user()->idUsers,
            'Updated default stock levels' . (!empty($validated['apply_to_existing'])
                ? " (applied to {$updated['school_equipment']} equipment and {$updated['type_b']} Type B item(s))"
                : '')
        );

        return response()->json([
            'message' => 'Stock levels updated.',
            'updated' => $updated,
            'school_equipment' => self::stockLevelsFor('school_equipment'),
            'type_b' => self::stockLevelsFor('type_b'),
        ]);
    }

    /* Default return days per category (null = none). Any role can read; only admin can change. */
    public function returnPeriods()
    {
        $defaults = ReturnPolicy::categoryDefaults();

        return response()->json([
            'office_supplies' => $defaults['Office Supplies'] ?? null,
            'school_equipment' => $defaults['School Equipment'] ?? null,
        ]);
    }

    public function updateReturnPeriods(Request $request)
    {
        $validated = $request->validate([
            'office_supplies' => 'present|nullable|integer|min:1|max:3650',
            'school_equipment' => 'present|nullable|integer|min:1|max:3650',
        ]);

        $map = [
            'office_supplies' => ReturnPolicy::CATEGORY_KEYS['Office Supplies'],
            'school_equipment' => ReturnPolicy::CATEGORY_KEYS['School Equipment'],
        ];

        foreach ($map as $field => $key) {
            if ($validated[$field] === null) {
                Setting::where('setting_key', $key)->delete();
            } else {
                Setting::updateOrCreate(
                    ['setting_key' => $key],
                    ['setting_value' => (string) $validated[$field]]
                );
            }
        }

        ReturnPolicy::forget();

        $this->logActivity(
            request()->user()->idUsers,
            'Updated default return periods'
        );

        return $this->returnPeriods();
    }
}
