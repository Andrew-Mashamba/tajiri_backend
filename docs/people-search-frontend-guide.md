# People Search Engine — Frontend Implementation Guide

## Endpoint

```
GET /api/people/search
```

No Bearer token required (public endpoint). Pass `user_id` as a query param to enable social features (mutual friends, friendship status, in_common, blocked-user filtering).

---

## Query Parameters

| Param | Type | Required | Default | Description |
|-------|------|----------|---------|-------------|
| `q` | string | No* | `""` | Search text (min 2 chars). Searches across: name, username, bio, location, schools, employer |
| `user_id` | int | Recommended | — | The logged-in user's ID. Enables: blocked filtering, mutual friends, friendship status, in_common |
| `page` | int | No | `1` | Page number |
| `per_page` | int | No | `20` | Results per page (max 50) |
| `sort` | string | No | `relevance` | Sort order (see Sort Options below) |
| `gender` | string | No | — | `male` or `female` |
| `online` | any | No | — | Pass `online=1` to show only currently-online users |
| `location` | string | No | — | Filter by region or district name (partial match) |
| `employer` | string | No | — | Filter by employer name (partial match) |
| `school` | string | No | — | Filter by any school level: primary, secondary, A-level, post-secondary, university (partial match) |
| `has_photo` | any | No | — | Pass `has_photo=1` to show only users with a profile photo |
| `age_min` | int | No | — | Minimum age (inclusive) |
| `age_max` | int | No | — | Maximum age (inclusive) |

> *Either `q` (min 2 chars) **or** at least one filter is required. Otherwise returns 400.

### Sort Options

| Value | Behavior |
|-------|----------|
| `relevance` | (Default) Friends-of-friends first, then exact name/username match quality, then by popularity (friends_count) |
| `last_seen` | Most recently active users first |
| `friends_count` | Most popular (highest friend count) first |
| `newest` | Newest accounts first |

---

## Response Format

### Success (200)

```json
{
  "success": true,
  "data": [
    {
      "id": 2,
      "first_name": "Andrew",
      "last_name": "Mashamba",
      "username": "andrew_m",
      "gender": "male",
      "age": 18,
      "profile_photo_path": "profile-photos/abc123.jpg",
      "cover_photo_path": "cover-photos/xyz789.jpg",
      "bio": "Developer from Dar",
      "region_name": "Dar-es-salaam",
      "district_name": "Kinondoni",
      "location_string": "Kinondoni, Dar-es-salaam",
      "relationship_status": "single",
      "friends_count": 42,
      "posts_count": 8,
      "photos_count": 6,
      "mutual_friends_count": 3,
      "friendship_status": "none",
      "in_common": [
        "Same district (Kinondoni)",
        "Same university (Aga Khan University)",
        "2 shared interests"
      ],
      "primary_school": "SARUJI PRIMARY SCHOOL",
      "secondary_school": "ST. MARY'S SEMINARY MBALIZI",
      "university": "Aga Khan University",
      "employer": "Zima Ltd",
      "is_online": true,
      "last_seen_at": "2026-02-16T17:00:33+00:00",
      "last_active_at": null,
      "created_at": "2026-01-10T11:56:27+00:00"
    }
  ],
  "meta": {
    "current_page": 1,
    "per_page": 20,
    "total": 47,
    "last_page": 3
  }
}
```

### Validation Error (400)

```json
{
  "success": false,
  "message": "Search query must be at least 2 characters, or provide a filter.",
  "errors": {
    "q": ["Minimum 2 characters required when no filters are set."]
  }
}
```

---

## Response Field Reference

### Identity

| Field | Type | Nullable | Description |
|-------|------|----------|-------------|
| `id` | int | No | User profile ID |
| `first_name` | string | No | First name |
| `last_name` | string | No | Last name |
| `username` | string | Yes | Username (may be null if user hasn't set one) |
| `gender` | string | Yes | `"male"` or `"female"` (null if not set) |
| `age` | int | Yes | Computed from date_of_birth (null if DOB not set) |
| `profile_photo_path` | string | Yes | Relative path — prepend your base storage URL |
| `cover_photo_path` | string | Yes | Relative path — prepend your base storage URL |
| `bio` | string | Yes | User's bio text |

### Location

| Field | Type | Nullable | Description |
|-------|------|----------|-------------|
| `region_name` | string | Yes | Region (e.g. "Dar-es-salaam") |
| `district_name` | string | Yes | District (e.g. "Kinondoni") |
| `location_string` | string | Yes | Pre-formatted: "District, Region" — use this for display |

### Social Stats

| Field | Type | Nullable | Description |
|-------|------|----------|-------------|
| `relationship_status` | string | Yes | One of: `single`, `in_relationship`, `engaged`, `married`, `complicated`, `divorced`, `widowed` |
| `friends_count` | int | No | Total friend count |
| `posts_count` | int | No | Total post count |
| `photos_count` | int | No | Total photo count |

### Social Signals (requires `user_id` param)

| Field | Type | Description |
|-------|------|-------------|
| `mutual_friends_count` | int | Number of friends you share with this person. Show as "X mutual friends" |
| `friendship_status` | string | Your relationship with this person (see table below) |
| `in_common` | string[] | Human-readable list of things you share (see below) |

#### `friendship_status` Values — What Button to Show

| Value | Meaning | UI Action |
|-------|---------|-----------|
| `"none"` | No relationship | Show **"Add Friend"** button |
| `"friends"` | Already friends | Show **"Friends"** badge (with option to unfriend) |
| `"pending_sent"` | You sent them a request | Show **"Request Sent"** (with option to cancel) |
| `"pending_received"` | They sent you a request | Show **"Accept / Decline"** buttons |

#### `in_common` Array — Display Examples

This array contains pre-formatted strings the backend generates by comparing the searcher's profile with each result. Display these as subtle tags/chips below the person card.

Possible values:
- `"Same district (Kinondoni)"`
- `"Same region (Dar-es-salaam)"`
- `"Same employer (Zima Ltd)"`
- `"Same university (Aga Khan University)"`
- `"Same secondary school (ST. MARY'S SEMINARY MBALIZI)"`
- `"Same primary school (SARUJI PRIMARY SCHOOL)"`
- `"Shared interest: Photography"`
- `"3 shared interests"`

The array is empty `[]` when there's nothing in common. Only show the section when the array is non-empty.

### Education & Work

| Field | Type | Nullable | Description |
|-------|------|----------|-------------|
| `primary_school` | string | Yes | Primary school name |
| `secondary_school` | string | Yes | Secondary school name |
| `university` | string | Yes | University name |
| `employer` | string | Yes | Employer name |

### Presence & Timestamps

| Field | Type | Nullable | Description |
|-------|------|----------|-------------|
| `is_online` | bool | No | Whether user is currently online |
| `last_seen_at` | string (ISO 8601) | Yes | Last time user was active (from presence heartbeat) |
| `last_active_at` | string (ISO 8601) | Yes | Last activity timestamp (from profile) |
| `created_at` | string (ISO 8601) | Yes | Account creation date |

---

## UI Implementation Guide

### 1. Screen Layout: Three Tabs

```
┌──────────────────────────────────────┐
│  [Search bar: "Search people..."]    │
│                                      │
│  ┌─────────┬──────────┬───────────┐  │
│  │ People  │ Friends  │ Requests  │  │
│  └─────────┴──────────┴───────────┘  │
│                                      │
│  [Gender: All | Men | Women]         │
│  [Sort: Relevance v]  [Filters]      │
│                                      │
│  ┌──────────────────────────────┐    │
│  │  Search results list ...     │    │
│  └──────────────────────────────┘    │
└──────────────────────────────────────┘
```

- **People tab** — Uses `GET /api/people/search` (this endpoint)
- **Friends tab** — Uses `GET /api/friends?user_id=X` (existing endpoint)
- **Requests tab** — Uses `GET /api/friends/requests?user_id=X` (existing endpoint)

### 2. Gender Filter (Primary Filter Bar)

Display as a segmented control / toggle group directly below the search bar:

```
  [ All ]  [ Men ]  [ Women ]
```

| Selection | API Call |
|-----------|----------|
| All | No `gender` param (omit it) |
| Men | `gender=male` |
| Women | `gender=female` |

This is a **major filter** — always visible, never hidden behind a "Filters" menu.

### 3. Sort Dropdown

Display as a dropdown or bottom sheet:

```
Sort by:
  (*) Relevance        ← default
  ( ) Recently Active
  ( ) Most Friends
  ( ) Newest
```

| UI Label | API `sort` value |
|----------|-----------------|
| Relevance | `relevance` |
| Recently Active | `last_seen` |
| Most Friends | `friends_count` |
| Newest | `newest` |

### 4. Advanced Filters (Filter Icon / Bottom Sheet)

Tapping a filter icon opens a bottom sheet:

```
┌─────────────────────────────────┐
│  Filters                    [X] │
│                                 │
│  Online now          [toggle]   │
│  Has profile photo   [toggle]   │
│                                 │
│  Age range                      │
│  [ 18 ]  ——————————  [ 45 ]    │
│                                 │
│  Location                       │
│  [ Dar-es-salaam          v ]   │
│                                 │
│  School                         │
│  [ Type school name...    ]     │
│                                 │
│  Employer                       │
│  [ Type employer name...  ]     │
│                                 │
│         [ Apply Filters ]       │
└─────────────────────────────────┘
```

| Filter | API Param | Widget |
|--------|-----------|--------|
| Online now | `online=1` | Toggle switch |
| Has profile photo | `has_photo=1` | Toggle switch |
| Age range | `age_min=18&age_max=45` | Range slider (min 18, max 80) |
| Location | `location=Dar-es-salaam` | Text field or region picker |
| School | `school=Aga Khan` | Text field with autocomplete |
| Employer | `employer=Zima` | Text field with autocomplete |

When filters are active, show a badge count on the filter icon (e.g. filter icon with "3" badge).

### 5. Person Card Design

```
┌─────────────────────────────────────────────┐
│  [Avatar]  Andrew Mashamba          [●] 🟢  │
│            @andrew_m · Male, 18             │
│            Kinondoni, Dar-es-salaam          │
│                                              │
│  ┌────────────────┐ ┌────────────────────┐  │
│  │ 🎓 Same uni    │ │ 📍 Same district   │  │
│  └────────────────┘ └────────────────────┘  │
│  3 mutual friends                            │
│                                              │
│                              [ Add Friend ]  │
└─────────────────────────────────────────────┘
```

#### Card Elements (top to bottom):

1. **Avatar** — `profile_photo_path` (prepend base URL). Show default avatar if null.
2. **Online indicator** — Green dot if `is_online == true`
3. **Name** — `first_name` + `last_name`
4. **Username** — `@username` (hide if null)
5. **Gender & Age** — `gender` capitalized + `age` (e.g. "Male, 18"). Hide section if both null.
6. **Location** — Use `location_string` directly (already formatted as "District, Region")
7. **In Common chips** — Render each string in `in_common[]` as a small colored chip/tag. Only show section if array is non-empty. Use icons:
   - "Same district" / "Same region" → location pin icon
   - "Same employer" → briefcase icon
   - "Same university" / "Same school" → graduation cap icon
   - "Shared interest" → heart/star icon
8. **Mutual friends** — Show `mutual_friends_count` as "X mutual friends" text. Hide if 0.
9. **Action button** — Based on `friendship_status`:
   - `"none"` → Blue **"Add Friend"** button → calls `POST /api/friends/request`
   - `"friends"` → Gray **"Friends"** checkmark badge
   - `"pending_sent"` → Outlined **"Requested"** → tap to cancel: `POST /api/friends/cancel/{id}`
   - `"pending_received"` → Two buttons: green **"Accept"** + gray **"Decline"**

### 6. Search Behavior

- **Debounce** text input by 400ms before firing API call
- **Minimum 2 characters** for text search — show a hint: "Type at least 2 characters to search"
- Filters alone (without text) are valid — e.g. `gender=female&online=1` works
- Show skeleton loading cards while fetching
- **Infinite scroll** pagination — increment `page` param as user scrolls. Stop when `current_page >= last_page`

### 7. Empty & Error States

| State | What to show |
|-------|-------------|
| No query yet | "Search for people by name, school, location, or employer" |
| Query too short (< 2 chars, no filters) | "Type at least 2 characters to search" |
| No results | "No people found. Try different keywords or filters." |
| API error | "Something went wrong. Tap to retry." |
| Filter active but 0 results | "No matches for these filters. Try broadening your search." |

---

## Example API Calls

### Basic text search
```
GET /api/people/search?q=andrew&user_id=2
```

### Women only, online, in Dar
```
GET /api/people/search?gender=female&online=1&location=Dar-es-salaam&user_id=2
```

### People aged 25-35, sorted by recently active
```
GET /api/people/search?age_min=25&age_max=35&sort=last_seen&user_id=2
```

### People from same university
```
GET /api/people/search?school=Aga%20Khan&user_id=2
```

### Men with profile photos, sorted by most friends
```
GET /api/people/search?gender=male&has_photo=1&sort=friends_count&user_id=2
```

### Paginated results (page 2)
```
GET /api/people/search?q=an&page=2&per_page=15&user_id=2
```

---

## Dart Model

```dart
class PersonSearchResult {
  final int id;
  final String firstName;
  final String lastName;
  final String? username;
  final String? gender;
  final int? age;
  final String? profilePhotoPath;
  final String? coverPhotoPath;
  final String? bio;
  final String? regionName;
  final String? districtName;
  final String? locationString;
  final String? relationshipStatus;
  final int friendsCount;
  final int postsCount;
  final int photosCount;
  final int mutualFriendsCount;
  final String friendshipStatus; // "none" | "friends" | "pending_sent" | "pending_received"
  final List<String> inCommon;
  final String? primarySchool;
  final String? secondarySchool;
  final String? university;
  final String? employer;
  final bool isOnline;
  final DateTime? lastSeenAt;
  final DateTime? lastActiveAt;
  final DateTime? createdAt;

  PersonSearchResult({
    required this.id,
    required this.firstName,
    required this.lastName,
    this.username,
    this.gender,
    this.age,
    this.profilePhotoPath,
    this.coverPhotoPath,
    this.bio,
    this.regionName,
    this.districtName,
    this.locationString,
    this.relationshipStatus,
    required this.friendsCount,
    required this.postsCount,
    required this.photosCount,
    required this.mutualFriendsCount,
    required this.friendshipStatus,
    required this.inCommon,
    this.primarySchool,
    this.secondarySchool,
    this.university,
    this.employer,
    required this.isOnline,
    this.lastSeenAt,
    this.lastActiveAt,
    this.createdAt,
  });

  factory PersonSearchResult.fromJson(Map<String, dynamic> json) {
    return PersonSearchResult(
      id: json['id'],
      firstName: json['first_name'],
      lastName: json['last_name'],
      username: json['username'],
      gender: json['gender'],
      age: json['age'],
      profilePhotoPath: json['profile_photo_path'],
      coverPhotoPath: json['cover_photo_path'],
      bio: json['bio'],
      regionName: json['region_name'],
      districtName: json['district_name'],
      locationString: json['location_string'],
      relationshipStatus: json['relationship_status'],
      friendsCount: json['friends_count'] ?? 0,
      postsCount: json['posts_count'] ?? 0,
      photosCount: json['photos_count'] ?? 0,
      mutualFriendsCount: json['mutual_friends_count'] ?? 0,
      friendshipStatus: json['friendship_status'] ?? 'none',
      inCommon: List<String>.from(json['in_common'] ?? []),
      primarySchool: json['primary_school'],
      secondarySchool: json['secondary_school'],
      university: json['university'],
      employer: json['employer'],
      isOnline: json['is_online'] ?? false,
      lastSeenAt: json['last_seen_at'] != null
          ? DateTime.parse(json['last_seen_at'])
          : null,
      lastActiveAt: json['last_active_at'] != null
          ? DateTime.parse(json['last_active_at'])
          : null,
      createdAt: json['created_at'] != null
          ? DateTime.parse(json['created_at'])
          : null,
    );
  }

  String get fullName => '$firstName $lastName';

  String get profilePhotoUrl =>
      profilePhotoPath != null
          ? 'https://zima-uat.site:8003/storage/$profilePhotoPath'
          : '';

  bool get hasProfilePhoto => profilePhotoPath != null;
}
```

---

## Service Layer

```dart
class PeopleSearchService {
  static const String _baseUrl = 'https://zima-uat.site:8003/api';

  Future<PeopleSearchResponse> search({
    String? query,
    int page = 1,
    int perPage = 20,
    String sort = 'relevance',
    String? gender,
    bool? online,
    String? location,
    String? employer,
    String? school,
    bool? hasPhoto,
    int? ageMin,
    int? ageMax,
    required int userId,
  }) async {
    final params = <String, String>{
      'user_id': userId.toString(),
      'page': page.toString(),
      'per_page': perPage.toString(),
      'sort': sort,
    };

    if (query != null && query.length >= 2) params['q'] = query;
    if (gender != null) params['gender'] = gender;
    if (online == true) params['online'] = '1';
    if (location != null) params['location'] = location;
    if (employer != null) params['employer'] = employer;
    if (school != null) params['school'] = school;
    if (hasPhoto == true) params['has_photo'] = '1';
    if (ageMin != null) params['age_min'] = ageMin.toString();
    if (ageMax != null) params['age_max'] = ageMax.toString();

    final uri = Uri.parse('$_baseUrl/people/search').replace(queryParameters: params);
    final response = await http.get(uri);
    final body = jsonDecode(response.body);

    if (body['success'] == true) {
      return PeopleSearchResponse(
        people: (body['data'] as List)
            .map((j) => PersonSearchResult.fromJson(j))
            .toList(),
        currentPage: body['meta']['current_page'],
        lastPage: body['meta']['last_page'],
        total: body['meta']['total'],
        perPage: body['meta']['per_page'],
      );
    } else {
      throw Exception(body['message'] ?? 'Search failed');
    }
  }
}

class PeopleSearchResponse {
  final List<PersonSearchResult> people;
  final int currentPage;
  final int lastPage;
  final int total;
  final int perPage;

  bool get hasMore => currentPage < lastPage;

  PeopleSearchResponse({
    required this.people,
    required this.currentPage,
    required this.lastPage,
    required this.total,
    required this.perPage,
  });
}
```

---

## Tab Navigation Context

The People Search lives inside a screen with 3 tabs:

| Tab | Label | Endpoint | Description |
|-----|-------|----------|-------------|
| 1 | **People** | `GET /api/people/search` | This endpoint. Search/discover all users. |
| 2 | **Friends** | `GET /api/friends?user_id=X` | Current friends list. |
| 3 | **Requests** | `GET /api/friends/requests?user_id=X` | Pending friend requests received. |

The search bar at the top should work for the **People** tab only. When on Friends/Requests tabs, the search bar can filter locally or use the existing friends search endpoint (`GET /api/users/search`).
