# Backend Directive: User File Management (Dropbox-like Cloud Storage)

**Audience:** Backend developers
**Purpose:** Single source of truth for API contracts required so the **Tajiri Flutter app** can use the "My Files" (Nyaraka Zangu) feature end-to-end.
**Date:** 2026-03-05
**Related:** [BACKEND.md](BACKEND.md) | [MESSAGES_BACKEND_IMPLEMENTATION_DIRECTIVE.md](MESSAGES_BACKEND_IMPLEMENTATION_DIRECTIVE.md)

---

## 1. Overview

The "My Files" feature provides users with personal cloud storage (similar to Dropbox/Google Drive). Users can:

- Upload, download, and manage files (documents, archives)
- Create folders and organize files hierarchically
- Star/favorite important files
- Mark files for offline access
- Share files with other users or generate public links
- Search across their files
- View storage quota usage

The Flutter client expects a **REST API** backend. All file APIs MUST:

- Use **Bearer token** authentication (`Authorization: Bearer {token}`)
- Use the **base path** under which the app is configured (e.g. `https://zima-uat.site:8003/api`)
- Store files securely (e.g., S3, local storage with proper access controls)
- Enforce per-user storage quotas

---

## 2. Authentication

| Context | How the app sends auth |
|---------|-------------------------|
| REST | `Authorization: Bearer {access_token}`. Also sends `user_id` in query/body for explicit user context. |
| File Downloads | Bearer token in header OR signed URLs with expiration. |

**Security Rules:**
- Users can only access their own files unless explicitly shared
- Shared file access requires valid share link or explicit permission
- Downloaded files should use signed URLs with short expiration (e.g., 1 hour)

---

## 3. Database Schema

### 3.1 `user_files` table
### 3.2 `user_file_shares` table
### 3.3 `user_storage_quotas` table

See migration file for exact schema.

---

## 4-11. See implementation in:
- Migration: `database/migrations/2026_03_05_100000_create_file_management_tables.php`
- Models: `app/Models/UserFile.php`, `app/Models/UserFileShare.php`, `app/Models/UserStorageQuota.php`
- Controller: `app/Http/Controllers/Api/FileController.php`
- Routes: `routes/api.php`
