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

## How to Use

After registering and logging in, you have **three ways** to track your cannabis strains:

### Method 1: Import Receipt (Track Purchases) 🧾

**Best for:** Tracking purchase history, spending, and maintaining complete records.

1. **Get Your Receipt:**
   - **Option A:** Go to your dispensary's website order page and copy the receipt text
   - **Option B:** Take a photo of your paper receipt with your phone

2. **Import the Receipt:**
   - Click the **"Import Receipt"** button on the main dashboard
   - Choose your method:
     - **"Paste Text" tab**: Paste your copied receipt text
     - **"Photo" tab**: Upload your receipt photo
   - Click **"Parse Receipt"**

3. **Review & Add:**
   - AI will extract all items, prices, strain names, and dispensary information
   - Review the extracted items
   - Click **"Add"** on individual items or **"Add All"** to import everything at once

**What gets captured:** Strain names, prices, purchase date, dispensary/store name, and all product details found in the receipt.

---

### Method 2: Scan Product Label (Track Strains Only) 🏷️

**Best for:** Tracking strains without purchase information (gifts, samples, or when you just want strain data).

1. **Start Adding:**
   - Click **"+ Add Strain"** on the main dashboard

2. **Upload Label Photo:**
   - Scroll to the **"Upload Label Photo (AI reads it)"** section
   - Click to upload or take a photo of the product label
   - Make sure the label is clear and readable

3. **AI Extraction:**
   - AI automatically reads and extracts:
     - Strain name
     - THC percentage
     - CBD percentage
     - Terpene profiles
     - Batch/lot information
   - Form fields are automatically filled with this data

4. **Complete & Save:**
   - Review the auto-filled information
   - Add your own rating and review
   - Optionally add purchase details (date, store, price) if you want
   - Click **"Add Strain"**

**What gets captured:** Strain information only (THC, CBD, terpenes, batch info). No purchase history unless you manually add it.

---

### Method 3: Manual Entry ✏️

**Best for:** Full control when you want to enter everything yourself.

1. Click **"+ Add Strain"**
2. Fill in all the details manually:
   - Strain name (required)
   - Type (Indica, Sativa, Hybrid)
   - THC/CBD percentages
   - Terpenes
   - Purchase information (optional): date, store, price
   - Rating and review
3. Click **"Add Strain"**

---

### Adding Photos to Existing Strains 📸

After adding a strain (by any method), you can add photos:

1. **Click on any strain card** to open the details view
2. Look for the **"Upload Photos"** section
3. Choose photo type:
   - **"Bud Photo"**: Pictures of the actual cannabis flower
   - **"Label Photo"**: Pictures of product labels/packaging
4. Photos appear in a gallery within each strain's details

**Note:** If you add a label photo to an existing strain, the AI can read it and update the strain information.

---

### Understanding Your Dashboard 📊

- **Stats Cards**: View total strains, money spent, average rating, and number of dispensaries
- **Search Bar**: Find strains by name instantly
- **Filter Chips**: Filter by type (All, Indica, Sativa, Hybrid)
- **Sort Options**: Sort by newest, oldest, name, rating, or price
- **Export**: Download your entire collection as JSON backup
- **Profile**: Update your info, change AI model preference, manage backups

---

### Pro Tips 💡

- **Mix and Match**: Use receipt imports for purchases and label scans for gifts or samples
- **Choose Your AI**: Go to Profile > Settings to select your preferred AI model (Google Gemini, DeepSeek, Llama, or Qwen)
- **Bulk Import**: Save time by photographing receipts instead of manually entering each strain
- **Label Quality**: For best AI reading results, ensure label photos are well-lit and in focus
- **Automatic Backups**: Your data is automatically backed up. Restore anytime from Profile > Backups
- **Track Spending**: Use the receipt import method to automatically track how much you spend at each dispensary

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
