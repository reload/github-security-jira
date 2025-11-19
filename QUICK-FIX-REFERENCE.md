# ⚡ Quick Fix Reference - HTTP 410 Error

## The Problem

```
Error searching for issue: Jira API error (HTTP 410):
Please migrate to the /rest/api/3/search/jql API
```

## The Solution (1 Line Change!)

**File:** `src/Jira/JiraV3Client.php`
**Line:** 104

### Change This:
```php
$result = $this->request('POST', '/search', $body);
```

### To This:
```php
$result = $this->request('POST', '/search/jql', $body);
```

## Why?

Jira Cloud v3 requires `/search/jql` endpoint, not `/search`.

## Quick Commands

```bash
# 1. Check if you have the fix
grep -n "'/search/jql'" src/Jira/JiraV3Client.php
# Should show: 104:        $result = $this->request('POST', '/search/jql', $body);

# 2. If not found, apply the fix manually or:
git pull origin YOUR_BRANCH

# 3. Verify syntax
php -l src/Jira/JiraV3Client.php

# 4. Test
php test-integration.php

# 5. Deploy
git add src/Jira/JiraV3Client.php
git commit -m "Fix: Use /search/jql endpoint for Jira Cloud v3"
git push
```

## Correct Endpoint Reference

| Endpoint | Status | Use For |
|----------|--------|---------|
| `/rest/api/3/search/jql` | ✅ Correct | Jira Cloud |
| `/rest/api/3/search` | ❌ Returns 410 | Don't use |
| `/rest/api/2/search` | ❌ Deprecated | Old v2 API |

## After Applying Fix

You should see:
- ✅ No more HTTP 410 errors
- ✅ "Existing issue XXX covers..." messages
- ✅ "Created issue XXX for..." messages
- ✅ Successful ticket creation

---

**See [CRITICAL-FIX.md](CRITICAL-FIX.md) for full details.**
