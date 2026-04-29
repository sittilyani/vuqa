<?php
/**
 * includes/county_access.php
 *
 * Central helper for the "assigned counties" security model.
 *
 * On login the user's tblusers.assigned_counties (JSON) is parsed and
 * stored in $_SESSION['assigned_counties'] as an array of int county_ids.
 *
 * Admin and Super Admin bypass the filter (they see every county).
 *
 * Usage:
 *   include_once __DIR__ . '/county_access.php';
 *   $sql = "SELECT * FROM counties WHERE 1=1" . cf_county_filter_sql();
 *   if (!cf_user_can_access_county($conn, $cid)) { ...deny... }
 */

if (!function_exists('cf_is_admin')) {
    function cf_is_admin() {
        $role = $_SESSION['role'] ?? $_SESSION['userrole'] ?? '';
        return in_array($role, ['Admin', 'Super Admin'], true);
    }
}

if (!function_exists('cf_assigned_ids')) {
    /**
     * Returns array of int county_ids assigned to the logged-in user.
     * Accepts the legacy/json `assigned_counties` session var OR the
     * comma-separated `assigned_county` (singular) column the registration
     * form writes to. Empty result means "no counties assigned" — for non-
     * admins, cf_county_filter_sql() then matches nothing.
     */
    function cf_assigned_ids() {
        $raw = $_SESSION['assigned_counties'] ?? null;
        if ($raw === null) $raw = $_SESSION['assigned_county'] ?? '';
        if (is_string($raw)) {
            $trim = trim($raw);
            if ($trim === '') return [];
            // Try JSON first, fall back to CSV
            $decoded = json_decode($trim, true);
            if (is_array($decoded)) {
                $raw = $decoded;
            } else {
                $raw = preg_split('/\s*,\s*/', $trim);
            }
        }
        if (!is_array($raw)) return [];
        $ids = [];
        foreach ($raw as $v) {
            $v = (int)$v;
            if ($v > 0) $ids[] = $v;
        }
        return array_values(array_unique($ids));
    }
}

if (!function_exists('cf_county_filter_sql')) {
    /**
     * Build a WHERE-clause fragment that restricts the query to the
     * user's assigned counties. Admins get an empty string (no filter).
     * Non-admins with NO assignments get a match-nothing clause so they
     * can't accidentally see everything.
     *
     * @param string $col  SQL column to compare (default county_id)
     * @return string
     */
    function cf_county_filter_sql($col = 'county_id') {
        if (cf_is_admin()) return '';
        $ids = cf_assigned_ids();
        if (empty($ids)) return " AND $col IN (0) ";  // match nothing
        $list = implode(',', array_map('intval', $ids));
        return " AND $col IN ($list) ";
    }
}

if (!function_exists('cf_user_can_access_county')) {
    /**
     * Returns true if the user is allowed to view/score the given county.
     * Admins always allowed. Non-admins must have it in their assigned list.
     */
    function cf_user_can_access_county($county_id) {
        if (cf_is_admin()) return true;
        $county_id = (int)$county_id;
        if ($county_id <= 0) return false;
        return in_array($county_id, cf_assigned_ids(), true);
    }
}

if (!function_exists('cf_load_counties')) {
    /**
     * Convenience helper for dropdowns. Returns rows of counties the user
     * is allowed to see, ordered alphabetically.
     */
    function cf_load_counties($conn, $extra_cols = '') {
        $cols = 'county_id, county_name';
        if (!empty($extra_cols)) $cols .= ', ' . $extra_cols;
        $sql = "SELECT $cols FROM counties WHERE 1=1" . cf_county_filter_sql() . " ORDER BY county_name";
        $rows = [];
        $r = mysqli_query($conn, $sql);
        if ($r) while ($row = mysqli_fetch_assoc($r)) $rows[] = $row;
        return $rows;
    }
}

if (!function_exists('cf_refresh_session_from_db')) {
    /**
     * Re-pull assigned counties from tblusers in case it changed mid-session.
     * Reads `assigned_county` (CSV) by preference; falls back to `assigned_counties`
     * (JSON) if the older column is the one in use.
     */
    function cf_refresh_session_from_db($conn) {
        $uid = (int)($_SESSION['user_id'] ?? 0);
        if (!$uid) return;
        // Try CSV column first
        $r = @mysqli_query($conn, "SELECT assigned_county FROM tblusers WHERE user_id=$uid LIMIT 1");
        if ($r && ($row = mysqli_fetch_assoc($r))) {
            $_SESSION['assigned_county'] = $row['assigned_county'] ?? '';
            return;
        }
        // Fall back to JSON column
        $r = @mysqli_query($conn, "SELECT assigned_counties FROM tblusers WHERE user_id=$uid LIMIT 1");
        if ($r && ($row = mysqli_fetch_assoc($r))) {
            $decoded = json_decode($row['assigned_counties'] ?? '', true);
            $_SESSION['assigned_counties'] = is_array($decoded) ? $decoded : [];
        }
    }
}
