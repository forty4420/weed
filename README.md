# Strain Tracker

A multi-user cannabis strain tracking web application with AI-powered label reading and receipt parsing.

## Features

- **Multi-user auth** - Registration, login, token-based sessions
- **Strain management** - Add, edit, delete, search, filter, sort
- **Photo uploads** - Multiple bud photos and label photos per strain
- **AI label reading** - Upload a label photo and AI extracts THC, CBD, terpenes, batch info
- **Receipt parsing** - Paste or photograph dispensary receipts to bulk-import strains
- **Community reviews** - Fetch AI-generated strain info (effects, terpenes, sentiment)
- **Stats dashboard** - Total strains, money spent, average rating, dispensary count
- **Cloud backup** - Automatic backups with restore capability
- **Data export** - Download your collection as JSON
- **Dark theme** - Green accent, mobile responsive, card-based UI

## Setup (Hostinger / Shared Hosting)

### 1. Upload Files

Upload these files to your hosting `public_html` directory (or subdirectory):

```
index.html
api.php
.htaccess
```

### 2. Configure API Key

Edit `api.php` and set your OpenRouter API key (line 8):

```php
define('OPENROUTER_API_KEY', 'sk-or-v1-your-key-here');
```

Get a free key at [openrouter.ai](https://openrouter.ai). Free models are available.

### 3. Configure Admin Password

Edit `api.php` (line 7):

```php
define('ADMIN_PASSWORD', 'your-secure-password');
```

### 4. Set API URL (if not in root)

If you deploy to a subdirectory, edit `index.html` and update the `API_URL` constant near the top of the `<script>` tag:

```javascript
const API_URL = '/your-subdirectory/api.php';
```

### 5. Verify Permissions

The web server needs write access to create `data/` and `uploads/` directories. On most shared hosting this works automatically. If not:

```bash
chmod 755 .
# Directories will be auto-created by the app
```

## Requirements

- PHP 7.4+ with `curl` extension
- Apache with `mod_rewrite` (standard on Hostinger)
- Write permissions for the web directory

## File Structure

```
/
├── index.html          # Frontend (single file)
├── api.php             # Backend API
├── .htaccess           # Apache config & security
├── README.md           # This file
├── data/               # Auto-created
│   ├── users/          # User JSON files
│   ├── backups/        # Automatic backups
│   └── tokens.json     # Auth tokens
└── uploads/            # Auto-created
    └── {userId}/       # Per-user photo storage
        └── {strainId}/ # Per-strain photos
```

## API Endpoints

| Method | Endpoint | Auth | Description |
|--------|----------|------|-------------|
| POST | `?action=register` | No | Create account |
| POST | `?action=login` | No | Log in |
| POST | `?action=logout` | Yes | Log out |
| GET/POST | `?action=profile` | Yes | Get/update profile |
| GET/POST | `?action=strains` | Yes | List/add strains |
| GET/POST/DELETE | `?action=strain&id=X` | Yes | Get/update/delete strain |
| POST | `?action=upload-photo` | Yes | Upload photo (multipart) |
| POST | `?action=delete-photo` | Yes | Delete photo |
| POST | `?action=read-label` | Yes | AI reads label photo |
| POST | `?action=parse-receipt` | Yes | AI parses receipt |
| POST | `?action=fetch-reviews` | Yes | Fetch AI strain reviews |
| GET | `?action=stats` | No | Public user/strain counts |
| GET | `?action=backups` | Yes | List backups |
| POST | `?action=restore` | Yes | Restore backup |
| GET | `?action=export` | Yes | Export data as JSON |

## AI Models (Free via OpenRouter)

- Google Gemini 2.0 Flash (default)
- DeepSeek V3
- Llama 4 Maverick
- Qwen3 235B

Users can select their preferred model in Profile > Settings.

## Security

- Passwords hashed with bcrypt
- Token-based authentication (30-day expiry)
- File upload validation (type + size)
- Data directory blocked via `.htaccess`
- Input sanitization throughout
