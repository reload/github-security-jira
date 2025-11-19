# Complete Endpoint Audit - Jira API v3

## Summary

✅ **All endpoints are now correct for Jira Cloud API v3**

The critical fix to use `/search/jql` instead of `/search` resolves both:
1. ✅ HTTP 410 errors
2. ✅ Duplicate ticket creation (root cause identified!)

---

## Complete Endpoint Inventory

### 1. Search Issues ✅ FIXED

**File:** [src/Jira/JiraV3Client.php:104](src/Jira/JiraV3Client.php#L104)

```php
$result = $this->request('POST', '/search/jql', $body);
```

**Full URL:** `https://missionwired.atlassian.net/rest/api/3/search/jql`

**Status:** ✅ Correct for Jira Cloud v3

**v2 → v3 Migration:**
- ❌ Old v2: `/rest/api/2/search`
- ❌ Wrong v3: `/rest/api/3/search` (returns HTTP 410 on Cloud)
- ✅ Correct v3: `/rest/api/3/search/jql`

**Request Format:**
```json
{
  "jql": "project = \"ITINF\" AND labels = \"unique-id\"",
  "startAt": 0,
  "maxResults": 1,
  "fields": ["key"]
}
```

**Response Format:**
```json
{
  "issues": [
    {"key": "ITINF-123"}
  ],
  "total": 1
}
```

---

### 2. Create Issue ✅ CORRECT

**File:** [src/Jira/JiraV3Client.php:119](src/Jira/JiraV3Client.php#L119)

```php
$result = $this->request('POST', '/issue', $body);
```

**Full URL:** `https://missionwired.atlassian.net/rest/api/3/issue`

**Status:** ✅ Correct for Jira Cloud v3

**v2 → v3 Migration:**
- ❌ Old v2: `/rest/api/2/issue`
- ✅ Correct v3: `/rest/api/3/issue`

**Request Format:**
```json
{
  "fields": {
    "project": {"key": "ITINF"},
    "issuetype": {"name": "Bug"},
    "summary": "lodash (4.17.21) - HIGH",
    "description": {
      "type": "doc",
      "version": 1,
      "content": [...]
    },
    "labels": ["repo-name", "unique-id", "custom-labels"]
  }
}
```

**Response Format:**
```json
{
  "key": "ITINF-2100",
  "id": "12345"
}
```

---

### 3. Add Watcher ✅ CORRECT

**File:** [src/Jira/JiraV3Client.php:134](src/Jira/JiraV3Client.php#L134)

```php
$this->request('POST', "/issue/{$issueKey}/watchers", $accountId);
```

**Full URL:** `https://missionwired.atlassian.net/rest/api/3/issue/ITINF-123/watchers`

**Status:** ✅ Correct for Jira Cloud v3

**v2 → v3 Migration:**
- ❌ Old v2: `/rest/api/2/issue/{key}/watchers`
- ✅ Correct v3: `/rest/api/3/issue/{key}/watchers`

**Request Format:**
```json
"557058:f58131cb-b67d-43c7-b30d-6b58d40bd077"
```
(Just the accountId as a string)

**Response:** 204 No Content (success)

---

### 4. Add Comment ✅ CORRECT

**File:** [src/Jira/JiraV3Client.php:142](src/Jira/JiraV3Client.php#L142)

```php
$result = $this->request('POST', "/issue/{$issueKey}/comment", $comment);
```

**Full URL:** `https://missionwired.atlassian.net/rest/api/3/issue/ITINF-123/comment`

**Status:** ✅ Correct for Jira Cloud v3

**v2 → v3 Migration:**
- ❌ Old v2: `/rest/api/2/issue/{key}/comment`
- ✅ Correct v3: `/rest/api/3/issue/{key}/comment`

**Request Format:**
```json
{
  "body": {
    "type": "doc",
    "version": 1,
    "content": [
      {
        "type": "paragraph",
        "content": [{"type": "text", "text": "This issue is being followed by 2 watcher(s)."}]
      }
    ]
  },
  "visibility": {
    "type": "role",
    "value": "Team Members"
  }
}
```

**Response Format:**
```json
{
  "id": "10000",
  "body": {...},
  "created": "2025-11-19T19:53:09.000+0000"
}
```

---

### 5. Find Users ✅ CORRECT

**File:** [src/Jira/JiraV3Client.php:156](src/Jira/JiraV3Client.php#L156)

```php
$endpoint = '/user/search?' . http_build_query(['query' => $query]);
$result = $this->request('GET', $endpoint);
```

**Full URL:** `https://missionwired.atlassian.net/rest/api/3/user/search?query=user@example.com`

**Status:** ✅ Correct for Jira Cloud v3

**v2 → v3 Migration:**
- ❌ Old v2: `/rest/api/2/user/search`
- ✅ Correct v3: `/rest/api/3/user/search`

**Request Format:** Query string parameter `?query=user@example.com`

**Response Format:**
```json
[
  {
    "accountId": "557058:f58131cb-b67d-43c7-b30d-6b58d40bd077",
    "emailAddress": "user@example.com",
    "displayName": "User Name"
  }
]
```

---

## HTTP Methods Verification

| Endpoint | Method | Correct? |
|----------|--------|----------|
| `/search/jql` | POST | ✅ Yes |
| `/issue` | POST | ✅ Yes |
| `/issue/{key}/watchers` | POST | ✅ Yes |
| `/issue/{key}/comment` | POST | ✅ Yes |
| `/user/search` | GET | ✅ Yes |

---

## Authentication Verification ✅

**File:** [src/Jira/JiraV3Client.php:38](src/Jira/JiraV3Client.php#L38)

```php
'Authorization: Basic ' . base64_encode($this->email . ':' . $this->token)
```

**Status:** ✅ Correct

**Format:** Basic Auth with `email:api_token` (base64 encoded)

This is the correct authentication method for Jira Cloud API v3.

---

## Content Type Headers ✅

**File:** [src/Jira/JiraV3Client.php:39-40](src/Jira/JiraV3Client.php#L39-L40)

```php
'Content-Type: application/json',
'Accept: application/json',
```

**Status:** ✅ Correct

Both request and response use JSON format, which is standard for Jira API v3.

---

## Why Duplicates Were Created

### The Bug Flow (Before Fix)

```
1. SyncCommand runs for js-yaml vulnerability
   ↓
2. Calls SecurityAlertIssue->exists()
   ↓
3. exists() calls searchIssues() with JQL:
   "project = ITINF AND labels = js-yaml:4.1.1"
   ↓
4. searchIssues() calls POST /rest/api/3/search
   ↓
5. ❌ Jira returns HTTP 410 (endpoint deprecated)
   ↓
6. Exception caught in exists(), returns null
   ↓
7. SyncCommand thinks: "No existing ticket found!"
   ↓
8. Calls ensure() → createIssue()
   ↓
9. ✅ Creates ticket ITINF-2100
   ↓
10. Next run: Same flow, search fails again
    ↓
11. Creates ANOTHER ticket ITINF-2101
    ↓
12. 🐛 DUPLICATE TICKETS!
```

### The Fix Flow (After Fix)

```
1. SyncCommand runs for js-yaml vulnerability
   ↓
2. Calls SecurityAlertIssue->exists()
   ↓
3. exists() calls searchIssues() with JQL:
   "project = ITINF AND labels = js-yaml:4.1.1"
   ↓
4. searchIssues() calls POST /rest/api/3/search/jql
   ↓
5. ✅ Jira returns HTTP 200 with results
   ↓
6. Result: {"issues": [{"key": "ITINF-2100"}]}
   ↓
7. exists() returns "ITINF-2100"
   ↓
8. SyncCommand: "Found existing ticket ITINF-2100!"
   ↓
9. SKIPS creation, logs:
   "Existing issue ITINF-2100 covers js-yaml:4.1.1"
   ↓
10. ✅ NO DUPLICATE!
```

---

## Cleanup Required

### You Now Have Duplicate Tickets Because:

**Before the fix was applied:**
- Multiple runs created duplicates (search always failed)
- Each run created a new ticket thinking none existed

**After the fix:**
- Search works correctly
- Finds existing tickets
- Won't create more duplicates ✅

### How to Clean Up Duplicates

**Option 1: Manual Cleanup (Recommended)**
1. Go to Jira
2. Search: `project = ITINF AND labels IN (ghas, vulns)`
3. Sort by created date
4. For each vulnerability, keep the OLDEST ticket
5. Close/delete newer duplicates as "Duplicate"
6. Link duplicates to the original (optional)

**Option 2: JQL Query to Find Duplicates**
```jql
project = ITINF AND labels IN (ghas, vulns)
ORDER BY created DESC
```

Group by unique ID label to identify duplicates.

**Option 3: Bulk Close via Jira**
1. Find duplicates
2. Bulk select newer ones
3. Bulk transition to "Closed" or "Duplicate"
4. Add comment: "Duplicate created due to API migration issue, keeping original ticket"

---

## Verification Steps

### 1. Verify No More 410 Errors

```bash
# Run the sync
./bin/ghsec-jira sync -vvv 2>&1 | grep "410"

# Should return: (nothing)
```

### 2. Verify Duplicate Detection Works

```bash
# Run twice in a row
./bin/ghsec-jira sync -vvv
# First run: "Created issue ITINF-XXX for package:version"

./bin/ghsec-jira sync -vvv
# Second run: "Existing issue ITINF-XXX covers package:version"
```

### 3. Check Endpoint in Code

```bash
grep -n "'/search" src/Jira/JiraV3Client.php
# Should show:
# 104:        $result = $this->request('POST', '/search/jql', $body);
```

---

## Endpoint Reference Card

| Operation | v2 (Old) | v3 (Cloud) | Status |
|-----------|----------|------------|--------|
| Search | `/api/2/search` | `/api/3/search/jql` | ✅ Fixed |
| Create | `/api/2/issue` | `/api/3/issue` | ✅ Always correct |
| Watchers | `/api/2/issue/{key}/watchers` | `/api/3/issue/{key}/watchers` | ✅ Always correct |
| Comments | `/api/2/issue/{key}/comment` | `/api/3/issue/{key}/comment` | ✅ Always correct |
| Users | `/api/2/user/search` | `/api/3/user/search` | ✅ Always correct |

---

## Final Status

✅ **All 5 endpoints verified correct for Jira Cloud API v3**

✅ **Duplicate ticket issue identified and root cause confirmed**

✅ **With the fix applied, no more duplicates will be created**

⚠️ **Action required:** Clean up existing duplicates manually in Jira

---

## Documentation Updated

- ✅ [CRITICAL-FIX.md](CRITICAL-FIX.md) - Explains the search endpoint fix
- ✅ [QUICK-FIX-REFERENCE.md](QUICK-FIX-REFERENCE.md) - One-page quick fix
- ✅ [IMPLEMENTATION-COMPLETE.md](IMPLEMENTATION-COMPLETE.md) - Updated endpoint table
- ✅ [V3-Implementation-Plan.md](V3-Implementation-Plan.md) - Updated endpoint table
- ✅ [ENDPOINT-AUDIT.md](ENDPOINT-AUDIT.md) - This comprehensive audit

---

**Audit Date:** November 19, 2025
**Audited By:** Claude Code
**Status:** ✅ All endpoints verified correct
