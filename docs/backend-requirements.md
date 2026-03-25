# TAJIRI Backend Requirements

This file collects all backend API requirements from each implemented story. Each story that needs backend data or sends data to the backend appends its requirements here.

## Format per story

```
## Story {id}: {title}
- Endpoints: [list]
- Request/response: [formats]
- Expectations: [what the frontend expects from backend]
```

---

## Story 4: Location Hierarchy Selection

- **Endpoints:**
  - `GET /api/locations/regions` – returns list of regions
  - `GET /api/locations/regions/{id}/districts` – returns districts for region
  - `GET /api/locations/districts/{id}/wards` – returns wards for district
  - `GET /api/locations/wards/{id}/streets` – returns streets for ward

- **Request/response:**
  - All are GET, no request body. Path parameter `{id}` is the integer ID of the parent entity.
  - Expected response shape (frontend parses `success` and `data`):
    ```json
    { "success": true, "data": [ ... ] }
    ```
  - **Regions:** each item `{ "id": int, "name": string, "post_code": string? }`
  - **Districts:** each item `{ "id": int, "region_id": int, "name": string, "post_code": string? }`
  - **Wards:** each item `{ "id": int, "district_id": int, "name": string, "post_code": string? }`
  - **Streets:** each item `{ "id": int, "ward_id": int, "name": string }`

- **Registration payload (location):**
  When the user completes registration, the frontend sends the selected location in the registration payload as part of `POST /api/users/register` (or equivalent). The `location` object is:
  ```json
  {
    "region_id": int,
    "region_name": string,
    "district_id": int,
    "district_name": string,
    "ward_id": int,
    "ward_name": string,
    "street_id": int,
    "street_name": string
  }
  ```
  It may be `null` if the user skipped the location step.

- **Expectations:**
  - Endpoints return 200 with JSON body; frontend expects `success === true` and `data` as array of objects with the fields above.
  - On non-200 or `success !== true`, frontend treats as failure and shows an error (e.g. "Imeshindwa kupakia mikoa" for regions).
  - Backend should accept and persist the `location` object in the registration payload when present.

---

## Story 6: Secondary School Picker

- **Endpoints**
  - `GET /api/secondary-schools/regions` — list regions with O-Level school counts.
  - `GET /api/secondary-schools/regions/{regionCode}/districts` — list districts in a region with school counts.
  - `GET /api/secondary-schools/districts/{districtCode}/schools` — list schools in a district. Optional query: `region_code` when `districtCode` is `OTHER`.
  - `GET /api/secondary-schools/search?q=...&limit=...` — search schools by name (and optionally by region/district). Optional query params: `region_code`, `district_code` to filter results.

- **Request/response**
  - All GET; no request body.
  - Response envelope: `{ "success": true, "data": [...] }`.
  - Region item: `{ "region": string, "region_code": string, "school_count": number }`.
  - District item: `{ "district": string, "district_code": string, "school_count": number }`.
  - School item: `{ "id": number, "code": string, "name": string, "type": "government"|"private", "region_code"?: string, "district_code"?: string, "region"?: string, "district"?: string }`.

- **Expectations**
  - Backend must expose 5,500+ O-Level secondary schools.
  - Search must support finding schools by **region**, **district**, and **name** (e.g. `q` may match name, region name, or district name; and/or `region_code`/`district_code` filter the result set).

---

## Story 5: Primary School Picker

- **Endpoints:**
  - `GET /api/schools/regions` – list regions with primary school counts
  - `GET /api/schools/regions/{region_code}/districts` – list districts in a region
  - `GET /api/schools/districts/{district_code}/schools` – list primary schools in a district
  - `GET /api/schools/search?q=...&limit=...&region_code=...&district_code=...` – search primary schools (16,000+ in Tanzania DB)

- **Request/response:**
  - **Regions:** `GET /api/schools/regions` → `{ "success": true, "data": [ { "region": string, "region_code": string, "school_count": number } ] }`
  - **Districts:** `GET /api/schools/regions/{region_code}/districts` → `{ "success": true, "data": [ { "district": string, "district_code": string, "school_count": number } ] }`
  - **Schools in district:** `GET /api/schools/districts/{district_code}/schools` → `{ "success": true, "data": [ { "id": number, "code": string, "name": string, "type": "government"|"private", "region": string?, "district": string? } ] }`
  - **Search:** `GET /api/schools/search?q=<query>&limit=30&region_code=<opt>&district_code=<opt>` → same school array as above; `region_code` and `district_code` optional filters.

- **Expectations:**
  - Frontend uses these for registration Step 3 (Primary School). SchoolPicker supports browse (region → district → school) and search with optional region/district filters.
  - Response must include `success: true` and `data` array. Empty array when no results. Errors: frontend treats non-200 or missing `success` as failure and shows retry/empty state.

---

## Story 8: University & Programme Picker

- **Endpoints:**
  - `GET /api/universities-detailed` – List all universities (optional query: `type` for filtering by type).
  - `GET /api/universities-detailed?type={type}` – List universities filtered by type.
  - `GET /api/universities-detailed/search?q={query}` – Search universities by name/code (query encoded).
  - `GET /api/universities-detailed/types` – List university types (e.g. public_university, private_university).
  - `GET /api/universities-detailed/{universityId}/colleges` – Colleges/schools/faculties for a university.
  - `GET /api/universities-detailed/colleges/{collegeId}/departments` – Departments for a college.
  - `GET /api/universities-detailed/departments/{departmentId}/programmes` – Programmes for a department.
  - `GET /api/universities-detailed/{universityId}/programmes` – All programmes for a university.
  - `GET /api/universities-detailed/programmes/search?q={query}` – Search programmes by name (optional: `&level={level}`).

- **Request/response:**
  - All GET; no request body.
  - Response envelope: `{ "success": true, "data": ... }`. On failure frontend expects non-200 or `success: false`.
  - **Universities list** (`data`): array of `{ id, code, name, acronym?, type, region?, established?, website? }`.
  - **Types** (`data`): map or array of type code → label.
  - **Colleges** (`data`): array of `{ id, code, name, type?, university_id }`.
  - **Departments** (`data`): array of `{ id, code, name, college_id }`.
  - **Programmes** (`data`): array of `{ id, code, name, level_code|degree_level, duration, college_id?, department_id?, university_id, department?, college?, university? }` (duration in years; optional display names for department, college, university).

- **Expectations:**
  - At least **50+ universities** with full hierarchy (colleges → departments → programmes).
  - TCU-aligned or equivalent reference data; types as in DOCS/design (e.g. public_university, private_university, public_college, private_college).
  - Search endpoints return results consistent with the same model shapes; programme search should include university (and optionally department/college) for display.

---

## Story 7: A-Level School & Combination Picker

- **Endpoints:**
  - `GET /api/alevel-schools/regions` – List regions with A-Level school counts.
  - `GET /api/alevel-schools/regions/{regionCode}/districts` – List districts in a region with school counts.
  - `GET /api/alevel-schools/districts/{districtCode}/schools` – List A-Level schools in a district (query param `region_code` when `districtCode` is `OTHER`). Backend must support 900+ schools across all regions/districts.
  - `GET /api/alevel-schools/search?q={query}&limit={limit}` – Search A-Level schools by name (frontend uses limit 30–50).
  - `GET /api/alevel-schools/combinations` – List all combinations (e.g. PCB, HGL) with code, name, category, subjects.
  - `GET /api/alevel-schools/{id}/combinations` – List combinations offered by a specific school (combination per school).

- **Request/response:**
  - All GET; no request body.
  - Response shape: `{ "success": true, "data": [...] }`. On error, frontend tolerates empty arrays or non-200.
  - **Regions:** `data` = list of `{ "region": string, "region_code": string, "school_count": number }`.
  - **Districts:** `data` = list of `{ "district": string, "district_code": string, "school_count": number }`.
  - **Schools:** `data` = list of `{ "id": number, "code": string, "name": string, "type": "government"|"private", "region_code"?: string, "district_code"?: string, "region"?: string, "district"?: string, "combinations"?: string[] }`.
  - **Combinations:** `data` = list of `{ "id": number, "code": string, "name": string, "category": string (e.g. "science","arts","business","language","religious"), "popularity"?: "high"|"medium"|"low", "subjects": string[], "careers"?: string[] }`.

- **Expectations:**
  - Backend provides 900+ A-Level schools via regions/districts and/or search.
  - When a school is selected, frontend calls `GET /api/alevel-schools/{id}/combinations` to show combinations for that school; if empty, it falls back to global combinations list.
  - Registration sends selected school id, combination code/name, and graduation year (stored in registration state and submitted with registration payload; see user/profile APIs for persistence).

---

## Story 2: Check Phone Availability

- **Endpoints:** `POST /api/users/check-phone`
- **Request:** JSON body `{ "phone_number": "<E.164 or +255XXXXXXXXX>" }`
- **Response (200):** JSON with availability status, e.g.:
  - `{ "available": true, "message": "optional" }` when the number is not yet registered, or
  - `{ "available": false, "message": "optional" }` when the number is already registered.
  - Backend may alternatively use `"exists": true/false`; frontend treats `exists: true` as unavailable.
- **Expectations:** Endpoint validates phone uniqueness. Frontend calls this from RegistrationScreen → PhoneStep before sending OTP. If unavailable, user sees an error and cannot proceed; if available, user sees confirmation and OTP flow continues.

---

## Story 9: Employer Picker (Business Database)

- **Endpoints**
  - `GET /api/businesses` — List all businesses (paginated or full). Frontend expects 750+ businesses (DSE, Parastatals, Corporates and others).
  - `GET /api/businesses/sectors` — List sectors (e.g. agriculture, mining). Response: `{ "success": true, "data": [ { "code": string, "label": string, "count"?: number } ] }`.
  - `GET /api/businesses/ownership-types` — List ownership types (e.g. government, private, public_listed, foreign). Response: `{ "success": true, "data": { <code>: <label> } }` or array of `{ "code", "label" }`.
  - `GET /api/businesses/sector/{sector}` — List businesses in a sector.
  - `GET /api/businesses/ownership/{ownership}` — List businesses by ownership type.
  - `GET /api/businesses/search?q={query}` — Search businesses by name/code (query encoded). Optional: filter by sector, category, ownership via query params.
  - `GET /api/businesses/parastatals` — List parastatal employers.
  - `GET /api/businesses/dse` — List DSE (Dar es Salaam Stock Exchange) listed companies.
  - `GET /api/businesses/{identifier}` — Get single business by id or code.

- **Request/response**
  - All GET; no request body.
  - Response envelope: `{ "success": true, "data": ... }`. List endpoints: `data` is array of business objects or `{ "data": [...] }` for paginated.
  - Business item: `{ "id": number, "code": string, "name": string, "acronym"?: string, "sector"?: string, "ownership"?: string, "category"?: string, "region"?: string }`. `ownership` values: e.g. government, private, public_listed, foreign.

- **Expectations**
  - Backend provides 750+ businesses. Search supports filtering by **sector**, **category** (DSE / Parastatal / Corporate or equivalent), and **ownership**.
  - Registration Step 8 (EmployerStep) uses these for employer selection; user can pick from DSE, Parastatals, Corporates (private) or search/filter by sector and ownership. Custom employer (free text) is supported client-side without backend.

---

## Story 1: Phone-Based Registration

- **Endpoints:**
  - `POST /api/users/register` — create UserProfile (phone as primary identifier).
  - `POST /api/users/check-phone` — validate phone number uniqueness against user_profiles (see Story 2).

- **Request (POST /api/users/register):**
  - JSON body from registration form (snake_case). Required: `first_name`, `last_name`, `phone_number`. Optional: `date_of_birth` (ISO 8601), `gender` (e.g. `male`/`female`), `is_phone_verified`, `location`, `primary_school`, `secondary_school`, `did_attend_alevel` (boolean; STORY-075 Education Path step — whether user attended A-Level), `alevel_education`, `postsecondary_education`, `university_education`, `current_employer`.
  - Example minimal: `{ "first_name": "Juma", "last_name": "Mohamed", "phone_number": "+255712345678", "date_of_birth": "2000-01-15", "gender": "male", "is_phone_verified": true }`. Nested objects for `location`, `primary_school`, etc. follow the same snake_case shape as in frontend `RegistrationState.toJson()`.

- **Response (201 success):**
  - `{ "success": true, "message": "optional", "data": { "id": <user_id>, "profile_photo_url": null, ... } }`. Frontend expects `data.id` as the created user ID and may apply `data` (e.g. `profile_photo_url`) to local state via `applyServerProfile`.

- **Response (422 validation / phone taken):**
  - `{ "success": false, "message": "...", "errors": { "phone_number": ["..."] } }`. Frontend shows message or `phone_number` error (e.g. phone already registered).

- **Expectations:**
  - Backend creates UserProfile; phone number must be unique (validated via check-phone and/or register).
  - Return success with user id and profile data so the app can store the user in Hive and navigate to profile.

---

## Story 11: Update Profile

- **Endpoints:** `PUT /api/users/phone/{phone}` – update profile for the user identified by phone.

- **Request:** Path parameter `{phone}` is the user's phone number (E.164 or +255XXXXXXXXX). URL-encode the phone value.
  JSON body (snake_case, all fields optional except those you wish to update):
  `first_name`, `last_name`, `date_of_birth` (ISO 8601 date string, e.g. `YYYY-MM-DD`), `gender` (`male`|`female`), `bio`, `username`, `relationship_status` (`single`|`married`|`engaged`|`complicated`), `interests` (array of strings).
  Example:
  `{ "first_name": "Juma", "last_name": "Mohamed", "date_of_birth": "2000-01-15", "gender": "male", "bio": "...", "username": "juma_m", "relationship_status": "single", "interests": ["muziki", "michezo"] }`

- **Response (200 success):**
  `{ "success": true, "message": "optional", "data": { ... } }`
  Frontend expects `success === true`. `data` may contain the updated profile (same shape as GET profile or registration) so the app can refresh local state.

- **Response (4xx / validation):**
  `{ "success": false, "message": "...", "errors": { "field": ["..."] } }`
  Frontend shows `message` or field errors to the user.

- **Expectations:**
  - Backend identifies the user by `phone` and updates only the provided fields.
  - After a successful PUT, the app syncs updated name, DOB, and gender to local storage (RegistrationState) and refreshes the profile view.

---

## Story 10: View User Profile (Wasifu)

- **Endpoints:**
  - `GET /api/users/{id}` – returns full profile for the given user id.

- **Request:**
  - GET, no body. Path parameter `{id}` is the integer user ID.
  - Optional query: `current_user_id=<id>` so the backend can return friendship status and visibility rules for the requesting user.

- **Response (200 success):**
  - Envelope: `{ "success": true, "data": { ... }, "message": "optional" }`.
  - `data` must include at least:
    - `id`, `first_name`, `last_name`, `created_at` (ISO 8601)
    - Optional: `username`, `phone_number`, `date_of_birth`, `gender`, `bio`, `interests` (array of strings), `relationship_status`
    - `profile_photo_url`, `cover_photo_url` (absolute URLs or paths the frontend can resolve)
    - `stats`: `{ "posts_count", "friends_count", "photos_count" }` (integers)
    - `location`: optional `{ "region_name", "district_name", "ward_name" }`
    - `education`: optional object with `primary_school`, `secondary_school`, `alevel`, `postsecondary`, `university` (each with `school_name`/`university_name`, `graduation_year`, etc. as used in registration)
    - `employer`: optional `{ "employer_name", "sector", "job_title", "ownership" }`
    - `friendship_status`: when `current_user_id` is sent, one of `"self"`, `"request_sent"`, `"request_received"`, `"accepted"` (or equivalent for "friends")
    - `mutual_friends_count`: optional integer when viewing another user

- **Response (non-200 or success: false):**
  - Frontend shows error message and "Jaribu tena" (retry) button.

- **Expectations:**
  - ProfileScreen (Wasifu) uses this to show name, photo, cover, bio, education, employer, and tabs (About, Posts, Photos, etc.). Backend must return full profile so the app can display all sections without extra round-trips for basic info.

---

## Story 12: Profile Photo Upload

- **Endpoints:** `POST /api/users/{id}/profile-photo`
- **Request:** `multipart/form-data` with a single file field. Field name: `photo`. Accepted: image file (e.g. JPEG, PNG). Frontend sends a compressed image (max width/height 800px, quality 85).
- **Response (200 success):**
  - `{ "success": true, "data": { "profile_photo_url": "<absolute URL or path>" }, "message": "optional" }`. Frontend expects `data.profile_photo_url` to update the profile and local user so the new photo is shown in profile and across the app (e.g. CreatePostScreen, conversations).
- **Response (non-200 or success: false):**
  - `{ "success": false, "message": "..." }`. Frontend shows the message in a SnackBar (e.g. "Imeshindwa kubadilisha picha").
- **Expectations:**
  - Only the authenticated user may upload for their own `id`. Backend stores the image and returns a URL (or path) that the frontend uses for display. Profile photo is displayed in a circular crop (ClipOval) in the profile header and anywhere the user avatar is shown.

---

## Story 13: Cover Photo Upload

- **Endpoints:** `POST /api/users/{id}/cover-photo`
- **Request:** `multipart/form-data` with a single file field. Field name: `photo`. Accepted: image file (e.g. JPEG, PNG). Frontend sends a compressed image (max width 1920, max height 1080, quality 85).
- **Response (200 success):**
  - `{ "success": true, "data": { "cover_photo_url": "<absolute URL or path>" }, "message": "optional" }`. Frontend expects `data.cover_photo_url` to refresh the profile and display the new cover at the top of the profile (banner).
- **Response (non-200 or success: false):**
  - `{ "success": false, "message": "..." }`. Frontend shows the message in a SnackBar (e.g. "Imeshindwa kubadilisha picha").
- **Expectations:**
  - Only the authenticated user may upload for their own `id`. Backend stores the image and returns a URL (or path). The cover photo is displayed as a banner at the top of the profile (FlexibleSpaceBar / header). Navigation: Home → Profile (Mimi) → tap cover edit icon (camera) → image picker (gallery) → upload.

---

## Story 15: Create Post (Text)

- **Endpoints:** `POST /api/posts`
- **Request:**
  - JSON body (Content-Type: application/json). Required: `content` (string, max 5000 characters), `post_type` (string, value `"text"`), `user_id` (integer), `privacy` (string, e.g. `public`, `friends`, `private`). Optional: `background_color` (string, hex e.g. `#FF5733`) for text post background.
  - Example: `{ "user_id": 1, "content": "Hello world", "post_type": "text", "privacy": "public", "background_color": "#3498DB" }`.
- **Response (201 success):**
  - `{ "success": true, "message": "optional", "data": { ... } }`. Frontend expects `data` to be the created post object (same shape as in feed: id, user_id, content, post_type, privacy, background_color, created_at, user, etc.) so the feed can refresh and show the new post.
- **Response (4xx / validation):**
  - `{ "success": false, "message": "...", "errors": { "content": ["..."] } }`. Frontend shows message or field errors in a SnackBar.
- **Expectations:**
  - Backend creates a text post; stores `content`, `post_type=text`, `privacy`, and optional `background_color`. Navigation: Home → Feed → FAB (+) → CreatePostScreen → Text option → CreateTextPostScreen. After success, frontend pops with result true so feed/profile can refresh.

---

## Story 16: Create Post (Photo)

- **Endpoints:** `POST /api/posts`
- **Request:**
  - `multipart/form-data` when media is present. Required fields: `user_id` (integer), `post_type` (string, value `"photo"`), `privacy` (string, e.g. `public`, `friends`, `private`). Optional: `content` (string, caption). Media: one or more image files under field name `media[]` (array of files). Frontend sends up to 10 images, compressed (max width 1920, max height 1080, quality 85).
  - Example form fields: `user_id=1`, `post_type=photo`, `privacy=public`, `content=Optional caption`, `media[]=file1`, `media[]=file2`, ...
- **Response (201 success):**
  - `{ "success": true, "message": "optional", "data": { ... } }`. Frontend expects `data` to be the created post object (id, user_id, content, post_type, privacy, media URLs, created_at, user, etc.) so the feed can refresh and show the new post.
- **Response (4xx / 413 / validation):**
  - `{ "success": false, "message": "...", "errors": { ... } }`. Frontend shows message or field errors in a SnackBar. On 413 (file too large), frontend shows a user-friendly message.
- **Expectations:**
  - Backend creates a photo post; stores `content` (optional caption), `post_type=photo`, `privacy`, and one or more media files (images). Navigation: Home → Feed → FAB (+) → CreatePostScreen → Photo option → CreateImagePostScreen. Image picker allows multi-select up to 10 photos. After success, frontend pops with result true so feed/profile can refresh.

---

## Story 14: Username (@handle) Management

- **Endpoints:** `PUT /api/users/{id}/username`
- **Request:**
  - Path parameter `{id}` is the integer user ID.
  - JSON body: `{ "username": "<handle>" }`. Handle is a string: letters, numbers, and underscore only; frontend validates length 3–30 characters.
- **Response (200 success):**
  - `{ "success": true, "message": "optional", "data": { "username": "<updated_handle>" } }`. Frontend expects `data.username` to confirm the saved handle and displays it in profile and posts (e.g. `@handle`).
- **Response (4xx / validation / uniqueness):**
  - When the handle is already taken or invalid: `{ "success": false, "message": "..." }` or `{ "success": false, "errors": { "username": ["..."] } }`. Frontend shows the message in a SnackBar (e.g. "Jina tayari limetumika").
- **Expectations:**
  - Backend must validate uniqueness of `username` across users and return an error if the handle is taken.
  - Only the authenticated user may update their own `id`. The updated `username` is returned in `GET /api/users/{id}` (Story 10) and in post/comment user objects so the frontend can display `@username` in profile and in posts.

---

## Story 24: Share/Repost (Share to Wall)

- **Endpoints:** `POST /api/posts/{id}/share`
- **Request:**
  - Path parameter `{id}` is the **original** post ID (integer).
  - JSON body: `{ "user_id": <int>, "content": "<optional comment>", "privacy": "public"|"friends"|"private" }`.
- **Response (201 success):**
  - `{ "success": true, "message": "optional", "data": { ... } }`. Frontend expects `data` to be the **created shared post** object with: `id`, `user_id` (sharer), `original_post_id`, `content`, `post_type` (e.g. `shared`), `privacy`, `created_at`, `user` (sharer), `original_post` (nested full original post).
- **Response (4xx / success: false):**
  - `{ "success": false, "message": "..." }`.
- **Expectations:**
  - Backend creates a new post record with `original_post_id` set to the requested post ID; the new post appears on the sharer's wall and in feed. Shared posts must include `original_post_id` and preferably nested `original_post`.

---

## Story 25: Save/Bookmark Post

- **Endpoints:**
  - `POST /api/posts/{id}/save` – Save (bookmark) a post.
  - `DELETE /api/posts/{id}/save` – Remove a saved post.
  - `GET /api/posts/saved` – List saved posts.
- **Request (POST save):** `{ "user_id": int }`
- **Request (DELETE save):** `{ "user_id": int }`
- **Request (GET saved):** `user_id`, `page`, `per_page`
- **Response (POST/DELETE save):** `{ "success": true, "data": { "saves_count": int } }`
- **Response (GET saved):** `{ "success": true, "data": [ ... ], "meta": { ... } }`
- **Expectations:**
  - Save/unsave is idempotent. Single post response and feed should include `is_saved` and `saves_count`.

---

## Story 26: For You Feed

- **Endpoints:** `GET /api/posts/feed/for-you`
- **Request:** `user_id`, `page`, `per_page`
- **Response:** `{ "success": true, "data": [ ... ], "meta": { ... } }`
- **Expectations:**
  - Backend returns **personalized** posts ordered by **engagement-based ranking**.

---

## Story 22: Like/Unlike Post

- **Endpoints:**
  - `POST /api/posts/{id}/like` – like a post.
  - `DELETE /api/posts/{id}/like` – unlike a post.
- **Request (POST):** `{ "user_id": <int>, "reaction_type": "like" }`
- **Request (DELETE):** `{ "user_id": <int> }`
- **Response:** `{ "success": true, "data": { "likes_count": <int> } }`

---

## Story 17: Create Post (Audio)

- **Endpoints:** `POST /api/posts`
- **Request:** `multipart/form-data` with `user_id`, `post_type=audio`, `privacy`, optional `content`, `audio` (file), `audio_duration`, optional `cover_image`.
- **Response (201):** `{ "success": true, "data": { ...post with audio_path, audio_duration } }`

---

## Story 18: Create Short Video Post

- **Endpoints:** `POST /api/posts`
- **Request:** `multipart/form-data` with `user_id`, `post_type=short_video`, `privacy`, `is_short_video=true`, `media[]` (video), optional `cover_image`, `video_filter`, `video_speed`, `music_track_id`, `music_start_time`, `original_audio_volume`, `music_volume`.
- **Response (201):** `{ "success": true, "data": { ...post } }`

---

## Story 19: View Post

- **Endpoints:**
  - `GET /api/posts/{id}` – single post with `current_user_id` for `is_liked`, `user_reaction`.
  - `GET /api/posts/{id}/comments?page=1&per_page=20` – paginated comments.
  - `POST /api/posts/{id}/comments` – add comment. Body: `{ "user_id", "content", "parent_id"? }`.

---

## Story 20: Edit Post

- **Endpoints:** `PUT /api/posts/{id}`
- **Request:** JSON body with optional `content`, `privacy`, `location_name`, `is_pinned`.
- **Response (200):** `{ "success": true, "data": { ...updated post } }`
- **Expectations:** Only post author may update. Backend may enforce edit time limit.

---

## Story 27: Following Feed

- **Endpoints:** `GET /api/posts/feed/following`
- **Request:** `user_id`, `page`, `per_page`
- **Response:** `{ "success": true, "data": [ ... ], "meta": { ... } }`
- **Expectations:** Only posts from friends, chronological order (newest first).

---

## Story 21: Delete Post

- **Endpoints:** `DELETE /api/posts/{id}`
- **Response:** `{ "success": true }`. Soft-delete.

---

## Story 23: Comment on Post

- **Endpoints:**
  - `GET /api/posts/{id}/comments` – paginated comments.
  - `POST /api/posts/{id}/comments` – add comment with optional `parent_id` for threaded replies.
- **Response (GET):** `{ "success": true, "data": [ ...comments ], "meta": { ... } }`
- **Response (POST 201):** `{ "success": true, "data": { ...comment } }`

---

## Story 29: Discover Feed

- **Endpoints:**
  - `GET /api/feed/discover` – discovery feed.
  - `GET /api/feed/trending` – trending posts.
  - `GET /api/feed/nearby` – posts from user's region.
- **Request:** `page`, `per_page`, optional `user_id`, optional `region_id` for nearby.
- **Response:** `{ "success": true, "data": [ ... ], "meta": { ... } }`

---

## Story 30: Post Drafts

- **Endpoints:**
  - `POST /api/drafts` – Create/update draft (multipart).
  - `GET /api/drafts` – List drafts.
  - `POST /api/drafts/{id}/publish` – Publish draft.

---

## Story 28: Shorts Feed

- **Endpoints:** `GET /api/posts/feed/shorts`
- **Request:** `user_id`, `page`, `per_page`
- **Response:** `{ "success": true, "data": [ ... ], "meta": { ... } }`
- **Expectations:** Only short-form video posts (is_short_video: true).

---

## Story 31: Upload Photo (to Albums)

- **Endpoints:**
  - `POST /api/photos` – Upload photo.
  - `GET /api/users/{userId}/albums` – List albums.
  - `POST /api/albums` – Create album.

---

## Story 32: Create & Manage Albums

- **Endpoints:**
  - `POST /api/albums`, `GET /api/albums`, `GET /api/albums/{id}`, `PUT /api/albums/{id}`, `DELETE /api/albums/{id}`
- **Expectations:** Privacy per album (public/friends/private). Owner-only edit/delete.

---

## Story 33: View Photo Gallery

- **Endpoints:**
  - `GET /api/users/{userId}/photos?page=1&per_page=20`
  - `GET /api/users/{userId}/albums`
  - `GET /api/albums/{albumId}?page=1&per_page=20`
  - `GET /api/photos/{photoId}`
  - `DELETE /api/photos/{photoId}`

---

## Story 34: Send Friend Request

- **Endpoints:** `POST /api/friends/request`
- **Request:** `{ "user_id": <int>, "friend_id": <int> }`
- **Response (201):** `{ "success": true, "data": { ... } }`

---

## Story 35: Accept/Decline Friend Request

- **Endpoints:**
  - `GET /api/friends/requests` – pending requests (received + sent).
  - `POST /api/friends/accept/{id}` – accept.
  - `POST /api/friends/decline/{id}` – decline.

---

## Story 37: Friend Suggestions

- **Endpoints:** `GET /api/friends/suggestions`
- **Request:** `user_id`, `limit`
- **Response:** `{ "success": true, "data": [ ...users with mutual_friends_count ] }`

---

## Story 36: Friends List

- **Endpoints:**
  - `GET /api/friends` – list friends.
  - `DELETE /api/friends/{id}` – remove friend.

---

## Story 38: Conversations List

- **Endpoints:** `GET /api/conversations`
- **Request:** `user_id`, `page`, `per_page`
- **Response:** Conversations with `last_message`, `unread_count`, `participants`.

---

## Story 39: Private Chat

- **Endpoints:**
  - `GET /api/conversations/private/{userId}` – get/create private conversation.
  - `POST /api/conversations/{id}/messages` – send message (text/image/video/audio).

---

## Story 40: Typing Indicator

- **Endpoints:**
  - `POST /api/conversations/{id}/typing/start`
  - `POST /api/conversations/{id}/typing/stop`
  - `GET /api/conversations/{id}/typing?user_id={userId}`

---

## Story 42: Join/Leave Group

- **Endpoints:**
  - `POST /api/groups/{id}/join` – join (public: immediate; private: pending approval).
  - `POST /api/groups/{id}/leave` – leave.

---

## Story 41: Create Group

- **Endpoints:** `POST /api/groups`
- **Request:** multipart with `creator_id`, `name`, `description`, `privacy`, `requires_approval`, optional `cover_photo`.
- **Response (201):** `{ "success": true, "data": { ...group } }`

---

## Story 44: Create Page

- **Endpoints:**
  - `POST /api/pages` – create page.
  - `GET /api/pages/categories` – list categories.

---

## Story 90: Events Screen (Browse Events)

- **Endpoints:**
  - `GET /api/events` – list events (upcoming).
  - `GET /api/events/user` – user's events by filter.

---

## Story 46: Create Event

- **Endpoints:** `POST /api/events`
- **Request:** multipart with `creator_id`, `name`, `start_date`, optional fields.
- **Response (201):** `{ "success": true, "data": { ...event } }`

---

## Story 45: Follow/Like Page

- **Endpoints:**
  - `POST /api/pages/{id}/follow`, `DELETE /api/pages/{id}/follow`
  - `POST /api/pages/{id}/like`
- **Response:** `{ "success": true, "data": { "followers_count"|"likes_count": int } }`

---

## Story 43: Group Posts

- **Endpoints:**
  - `GET /api/groups/{id}/posts` – list group posts.
  - `POST /api/groups/{id}/posts` – create group post (multipart).

---

## Story 47: Event RSVP

- **Endpoints:**
  - `POST /api/events/{id}/respond` – RSVP (going/interested/not_going).
  - `DELETE /api/events/{id}/respond` – remove response.
  - `GET /api/events/{id}/attendees?type={type}` – list attendees.

---

## Story 48: Create Poll

- **Endpoints:** `POST /api/polls`
- **Request:** `{ "creator_id", "question", "options": [...], "ends_at"?, "is_multiple_choice"?, "is_anonymous"? }`
- **Response (201):** `{ "success": true, "data": { ...poll } }`

---

## Story 49: Vote on Poll

- **Endpoints:**
  - `POST /api/polls/{id}/vote` – vote.
  - `DELETE /api/polls/{id}/vote` – unvote.
- **Response:** Updated poll with option counts.

---

## Story 50: Create Story

- **Endpoints:** `POST /api/stories`
- **Request:** multipart with `user_id`, `media_type`, optional `media`, `caption`, `duration`, `filter`, `background_color`, `text_overlays`, `stickers`, `privacy`, `allow_replies`, `allow_sharing`.
- **Response (201):** `{ "success": true, "data": { ...story } }`

---

## Story 51: View Stories

- **Endpoints:**
  - `GET /api/stories` – story groups.
  - `POST /api/stories/{id}/view` – record view.

---

## Story 53: Create Clip

- **Endpoints:** `POST /api/clips`
- **Request:** multipart with `video`, `user_id`, optional `caption`, `music_id`, `filter`, `hashtags`, `privacy`, etc.
- **Response (201):** `{ "success": true, "data": { ...clip } }`

---

## Story 54: Clips Feed & Player

- **Endpoints:**
  - `GET /api/clips` – clips feed.
  - `GET /api/clips/trending` – trending clips.

---

## Story 52: Story Highlights

- **Endpoints:**
  - `POST /api/stories/highlights` – create highlight.
  - `GET /api/stories/highlights/{userId}` – list highlights.
  - `POST /api/stories/highlights/{id}/stories` – add story to highlight.

---

## Story 56: Upload Music

- **Endpoints:**
  - `POST /api/music/extract-metadata` – upload + extract metadata.
  - `POST /api/music/upload-chunk` – chunked upload.
  - `POST /api/music/finalize-upload` – finalize track.
  - `POST /api/music/cancel-upload` – cancel upload.

---

## Story 55: Music Library

- **Endpoints:**
  - `GET /api/music` – list tracks.
  - `GET /api/music/trending` – trending tracks.

---

## Story 78: Profile Music Gallery Tab

- **Endpoints:** `GET /api/music/user/:userId`

---

## Story 58: Watch Live Stream

- **Endpoints:**
  - `GET /api/streams` – list streams.
  - `POST /api/streams/{id}/join` – join.
  - `POST /api/streams/{id}/leave` – leave.

---

## Story 59: Initiate Voice/Video Call

- **Endpoints:**
  - `POST /api/calls/initiate` – initiate call.
  - `POST /api/calls/{callId}/answer`, `POST /api/calls/{callId}/decline`, `POST /api/calls/{callId}/end`
  - `GET /api/calls/{callId}/status?user_id={userId}`
  - `GET /api/calls/history?user_id={userId}&page=...`

---

## Story 87: Call History

- **Endpoints:** `GET /api/calls/history?user_id={userId}&page=...`

---

## Story 57: Go Live

- **Endpoints:**
  - `POST /api/streams` – create stream.
  - `POST /api/streams/{id}/start` – go live.

---

## Story 79: Profile Live Gallery Tab

- **Endpoints:** `GET /api/streams/user/{userId}`

---

## Story 62: Deposit/Withdraw (Mobile Money)

- **Endpoints:**
  - `POST /api/wallet/{userId}/deposit`
  - `POST /api/wallet/{userId}/withdraw`

---

## Story 61: Wallet Balance & Transactions

- **Endpoints:**
  - `GET /api/wallet/{userId}` – wallet balance.
  - `GET /api/wallet/{userId}/transactions` – transaction history.

---

## Story 60: Group Call

- **Endpoints:**
  - `POST /api/calls/group` – start/join group call.
  - `POST /api/calls/group/leave`
  - `PATCH /api/calls/group/state`

---

## Story 63: P2P Transfer

- **Endpoints:** `POST /api/wallet/{userId}/transfer`
- **Request:** `{ "amount", "pin", "description"?, "recipient_id"?, "recipient_phone"? }`

---

## Story 64: Creator Subscription Tiers

- **Endpoints:**
  - `POST /api/subscriptions/tiers` – create tier.
  - `GET /api/subscriptions/tiers/{creatorId}` – list tiers.

---

## Story 65: Subscribe to Creator

- **Endpoints:**
  - `POST /api/subscriptions` – subscribe.
  - `GET /api/subscriptions/check/{creatorId}?user_id={userId}` – check subscription.

---

## Story 67: User Search

- **Endpoints:** `GET /api/users/search?q={query}&page=...`

---

## Story 68: Hashtag Search

- **Endpoints:**
  - `GET /api/hashtags/trending`
  - `GET /api/hashtags/search?q={query}`
  - `GET /api/posts/hashtag/{tag}`

---

## Story 66: Send Tip

- **Endpoints:** `POST /api/subscriptions/tips`
- **Request:** `{ "user_id", "creator_id", "amount", "payment_method", "message"? }`

---

## Story 72: Resumable Chunked Uploads

- **Endpoints:**
  - `POST /api/uploads/init`, `POST /api/uploads/{id}/chunk`, `POST /api/uploads/{id}/complete`
  - `GET /api/uploads/{id}/status`, `GET /api/uploads/resumable`, `POST /api/uploads/{id}/cancel`

---

## Story 70: Privacy Settings

- **Endpoints:**
  - `GET /api/users/{userId}/privacy-settings`
  - `PUT /api/users/{userId}/privacy-settings`
- **Fields:** `profile_visibility`, `who_can_message`, `who_can_see_posts`, `last_seen_visibility`

---

## Story 77: Profile Video Gallery Tab

- **Endpoints:** `GET /api/clips/user/{userId}`

---

## Story 74: Registration Phone Step

- **Endpoints:** `POST /api/users/check-phone` (same as Story 2)

---

## Story 80: Create Campaign (Michango)

- **Endpoints:** `POST /api/campaigns`
- **Request:** multipart with `user_id`, `title`, `story`, `goal_amount`, `category`, optional fields.
- **Response (201):** `{ "success": true, "data": { ...campaign } }`

---

## Story 81: View & Manage Campaigns

- **Endpoints:**
  - `GET /api/users/{userId}/campaigns`
  - `GET /api/users/{userId}/campaigns/stats`
  - `GET /api/campaigns/{campaignId}`
  - `POST /api/campaigns/{campaignId}/publish|pause|resume|complete`
  - `DELETE /api/campaigns/{campaignId}`

---

## Story 82: Donate to Campaign

- **Endpoints:** `POST /api/campaigns/{id}/donate`
- **Request:** `{ "amount", "payment_method", "is_anonymous", "message"?, "pin"? }`

---

## Story 83: Campaign Withdrawals

- **Endpoints:**
  - `GET /api/campaigns/{campaignId}/withdrawals`
  - `POST /api/campaigns/{campaignId}/withdrawals`
  - `GET /api/users/{userId}/withdrawals`

---

## Story 84: Livestream Battle Mode (PK Battle)

- **Communication:** WebSocket battle events.
- **Events:** `invite_battle`, `accept_battle`, `decline_battle`, `forfeit_battle`, `battle_invite`, `battle_accepted`, `battle_score_update`, `battle_ended`.

---

## Story 85: Schedule Post for Later

- Uses existing draft endpoints with `scheduled_at` field.

---

## Story 86: @Mentions and #Hashtags in Posts

- Uses existing endpoints: `GET /api/friends`, `GET /api/users/search`, `GET /api/hashtags/trending`, `GET /api/hashtags/search`.

---

## Story 88: Feed Live Tab

- Uses `GET /api/streams?status=live` and `GET /api/streams?status=scheduled`.

---

## Story 89: Streams Screen

- Uses `GET /api/streams` with status filters.

---

## Story 93: Clip Video Search

- **Endpoints:**
  - `GET /api/clips/search?q={query}&type={type}&page=...`
  - `GET /api/clips/search/suggestions?q={query}`
  - `GET /api/users/{userId}/recent-searches?type=clips`
  - `POST /api/users/{userId}/recent-searches`
  - `DELETE /api/users/{userId}/recent-searches?type=clips`

---

## Story 94: Music Artist Detail

- **Endpoints:**
  - `GET /api/music/search?q={query}`
  - `GET /api/music/artists/{artistId}`
  - `POST /api/music/artists/{artistId}/follow` (optional)
  - `DELETE /api/music/artists/{artistId}/follow` (optional)

---

## Story 91: Groups Screen (Browse Groups)

- **Endpoints:**
  - `GET /api/groups?page=...&current_user_id=...`
  - `GET /api/groups/user?user_id=...`
  - `GET /api/groups/search?q=...`

---

## Story 95: Media Caching (Offline Viewing)

- Client-side only. No new backend endpoints.
