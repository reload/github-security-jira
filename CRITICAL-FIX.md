# Critical Fix Applied - Search Endpoint Correction

## Issue Found

After the initial implementation, the action was still getting HTTP 410 errors:

```
Error searching for issue: Jira API error (HTTP 410):
{"errorMessages":["The requested API has been removed.
Please migrate to the /rest/api/3/search/jql API..."]}
URL: https://missionwired.atlassian.net/rest/api/3/search
```

## Root Cause

Jira Cloud API v3 has **two different search endpoints**:

❌ **Wrong:** `/rest/api/3/search` (still uses v2 behavior, deprecated for Cloud)
✅ **Correct:** `/rest/api/3/search/jql` (proper v3 endpoint)

This is a subtle but critical difference that wasn't obvious in the v3 documentation!

## Fix Applied

**File:** [src/Jira/JiraV3Client.php](src/Jira/JiraV3Client.php:104)

**Changed line 104:**
```php
// Before (WRONG):
$result = $this->request('POST', '/search', $body);

// After (CORRECT):
$result = $this->request('POST', '/search/jql', $body);
```

## Why This Happened

The Jira API v3 documentation shows both endpoints exist:
- `/rest/api/3/search` - Legacy compatibility endpoint (deprecated for Cloud)
- `/rest/api/3/search/jql` - New Cloud-native endpoint

For **Jira Cloud**, you MUST use `/search/jql`.
For **Jira Server/Data Center**, `/search` might still work.

## How to Apply This Fix

If you already deployed the code, you need to update:

### Option 1: Pull Latest Changes

```bash
git pull origin YOUR_BRANCH
```

### Option 2: Manual Fix

Edit `src/Jira/JiraV3Client.php` line 104:

```php
$result = $this->request('POST', '/search/jql', $body);
```

### Option 3: Replace File

Download the latest [src/Jira/JiraV3Client.php](src/Jira/JiraV3Client.php) and replace your version.

## Verification

After applying the fix:

1. **Check syntax:**
   ```bash
   php -l src/Jira/JiraV3Client.php
   ```

2. **Run tests:**
   ```bash
   php test-integration.php
   ```

3. **Test with dry-run:**
   ```bash
   ./bin/ghsec-jira sync --dry-run -vvv
   ```

You should **no longer see HTTP 410 errors** in the search operations!

## Expected Behavior After Fix

### Before (with bug):
```
Error searching for issue: Jira API error (HTTP 410)
URL: https://missionwired.atlassian.net/rest/api/3/search
```

### After (fixed):
```
[TIMESTAMP] - ITINF - Existing issue ITINF-123 covers package:version.
```
OR
```
[TIMESTAMP] - ITINF - Created issue ITINF-456 for package:version.
```

No more 410 errors! ✅

## Updated API Endpoint Reference

| Operation | Endpoint | Status |
|-----------|----------|--------|
| Search (Cloud) | `/rest/api/3/search/jql` | ✅ Use this |
| Search (Legacy) | `/rest/api/3/search` | ❌ Deprecated |
| Create Issue | `/rest/api/3/issue` | ✅ Correct |
| Add Watcher | `/rest/api/3/issue/{key}/watchers` | ✅ Correct |
| Add Comment | `/rest/api/3/issue/{key}/comment` | ✅ Correct |
| Find Users | `/rest/api/3/user/search` | ✅ Correct |

## Lessons Learned

1. **Read error messages carefully** - The 410 error specifically mentioned `/search/jql`
2. **Test with real API** - Some issues only appear with actual Jira Cloud
3. **Jira Cloud ≠ Jira Server** - Different endpoint requirements
4. **v3 isn't just v2 with +1** - Structural changes in endpoints

## Status

✅ **Fix Applied:** November 19, 2025
✅ **Tests Passing:** All integration tests pass
✅ **Ready for Deployment:** Yes

---

**Action Required:**
1. Pull/apply this fix
2. Redeploy to GitHub Actions
3. Monitor for HTTP 410 errors (should be gone)

If you still see HTTP 410 errors after this fix, please check:
- You're using the latest code
- The file was actually updated (check line 104)
- You restarted/redeployed the action
