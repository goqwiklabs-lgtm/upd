# CloudDrive - Multi-Account Google Storage Platform with InfinityFree & PHP

A personal Google Drive-style cloud storage platform that pools **multiple Gmail accounts (capped at 13 GB each)** while running on **InfinityFree PHP & MySQL**.

---

## ✨ Features Included

1. **🏀 Basketball Hoop Shot Animation Dropzone** (From your screen recording):
   - Interactive drag-and-drop / "take the shot" file upload area.
   - Parabolic dotted flight arc trajectory as files fly into the basketball hoop.
   - Net swish animation + `+1` score pop badge over the backboard.
   - Floating upload dock with live progress bar, file size, and `Uploaded ✓` check badge.

2. **⚡ Multi-Account Google Drive Storage Pooling (13 GB Auto-Switching)**:
   - Admin can connect multiple Gmail accounts (Account 1, Account 2, Account 3...).
   - Each Gmail account is capped at **13 GB** (leaving a 2 GB safety buffer for personal emails).
   - When Account 1 hits 13 GB, uploads automatically roll over to Account 2, Account 3, etc.
   - Admin Panel displays storage meters (e.g. `8.4 GB / 13 GB used`), active toggles, and live quota sync.

3. **🚀 Direct Browser-to-Google Resumable Upload (2GB+ & Mobile Background Friendly)**:
   - Completely bypasses InfinityFree's 10 MB upload limit and execution timeouts.
   - User's mobile/desktop browser streams the file **directly to Google's Resumable Upload API** in chunks.
   - If the user minimizes Chrome to watch YouTube, the upload remains active or seamlessly resumes.

4. **📁 Full File & Folder Management**:
   - Create folders, nested sub-folders, and breadcrumb navigation.
   - 3-Dot context menu on every file:
     - 🗑️ **Delete:** Deletes from Google Drive and database.
     - ✏️ **Rename:** Renames in Google Drive and database.
     - 📁 **Move:** Move files between folders.
     - 📋 **Copy:** Duplicates file on Google Drive and database.
     - 🔗 **Share:** Toggle public access, copy share link.

5. **🎥 Integrated In-App Previewers**:
   - **Video Player:** Custom player with HTTP range streaming for `.mp4`, `.mkv`, `.webm`, `.mov`.
   - **Image Viewer:** Lightbox with zoom and rotation for `.jpg`, `.png`, `.gif`, `.webp`.
   - **PDF Viewer:** Embedded viewer for `.pdf`.
   - **Audio Player:** In-app player for `.mp3`, `.wav`.
   - **Text & Code Viewer:** Dark syntax-styled viewer for `.txt`, `.json`, `.js`, `.py`, `.php`, etc.

6. **🛡️ Admin Panel (`admin.php`)**:
   - Storage Pool Overview (total accounts, total pool capacity, total used).
   - Connect and manage multiple Gmail accounts.
   - Global file manager (admin can view or delete any file uploaded by any user).
   - User accounts manager.

---

## 🚀 How to Deploy on InfinityFree

### Step 1: Set up MySQL Database on InfinityFree
1. Log in to your **InfinityFree Control Panel** (vPanel).
2. Go to **MySQL Databases** and create a new database.
3. Open **phpMyAdmin** for that database.
4. Click the **Import** tab, select [`schema.sql`](schema.sql), and click **Go**.

### Step 2: Configure Database Credentials
Open [`config/db.php`](config/db.php) and update your InfinityFree credentials:
```php
define('DB_HOST', 'sqlXXX.infinityfree.com'); // Your MySQL Host
define('DB_NAME', 'epiz_xxxxxxx_dbname');      // Your Database Name
define('DB_USER', 'epiz_xxxxxxx');             // Your MySQL Username
define('DB_PASS', 'YOUR_VPANEL_PASSWORD');     // Your vPanel Password
```

### Step 3: Upload Files to InfinityFree
Using an FTP client like **FileZilla** (or the InfinityFree Online File Manager):
- Connect to your FTP account.
- Upload all files from this project into your **`htdocs`** directory.

---

## 🔑 How to Connect Your Gmail Accounts (3-Minute Setup)

### Step 1: Create Google Cloud OAuth Credentials
1. Go to [Google Cloud Console](https://console.cloud.google.com/) and create a project (e.g. `My Cloud Storage`).
2. Go to **APIs & Services** > **Library**, search for **Google Drive API**, and click **Enable**.
3. Go to **APIs & Services** > **OAuth consent screen**:
   - User Type: **External** -> Click **Create**.
   - App Name: `CloudDrive`, enter your email, and save.
   - In **Publishing status**, keep it as **Testing** and add your Gmail account under **Test users**.
4. Go to **Credentials** > **Create Credentials** > **OAuth client ID**:
   - Application Type: **Web application**.
   - Authorized redirect URIs: Add `https://developers.google.com/oauthplayground`.
   - Click **Create** and copy your **Client ID** and **Client Secret**.

### Step 2: Generate the Refresh Token (1 Minute via OAuth Playground)
1. Open [Google OAuth 2.0 Playground](https://developers.google.com/oauthplayground).
2. Click the ⚙️ **Gear icon** in the top right:
   - Check **Use your own OAuth credentials**.
   - Paste your **Client ID** and **Client Secret**.
3. In **Step 1 (Select & authorize APIs)** on the left:
   - Scroll down to **Drive API v3** and select `https://www.googleapis.com/auth/drive`.
   - Click the blue **Authorize APIs** button.
4. Log into the Gmail account you want to use for storage and click **Allow**.
5. In **Step 2 (Exchange authorization code for tokens)**:
   - Click **Exchange authorization code for tokens**.
   - Copy the generated **Refresh token**!

### Step 3: Connect the Account in Admin Panel
1. Open your website: `https://your-domain.infinityfreeapp.com/admin.php`.
2. Log in with the default admin credentials:
   - **Username:** `admin`
   - **Password:** `admin123` *(Be sure to change this in your profile!)*
3. Click **Connect New Gmail Account**:
   - Enter your **Gmail address**
   - Paste your **Client ID**
   - Paste your **Client Secret**
   - Paste your **Refresh Token**
4. Click **Verify & Connect**. The system will test the connection, sync the live storage quota, and activate it in the storage pool!
5. Repeat this step for any additional Gmail accounts. When Account 1 hits 13 GB, Account 2 takes over automatically!

---

## 📂 Project Structure

```
├── config/
│   ├── db.php                 # MySQL PDO connection with InfinityFree support
│   └── google.php             # Google Drive API v3 lightweight manager & 13GB pool logic
├── api/
│   ├── auth.php               # Login, Register, Logout, Session check
│   ├── upload_init.php        # Checks 13GB pool & gets Google resumable session URI
│   ├── upload_finish.php      # Saves file record & updates account storage stats
│   ├── files.php              # Files/folders listing, rename, delete, copy, move, share
│   └── admin.php              # Admin account manager, global file browser, user list
├── assets/
│   ├── css/style.css          # Basketball hoop animation, trajectory arc, +1 counter styles
│   ├── js/app.js              # Frontend UI controller, chunked resumable uploader
│   └── js/admin.js            # Admin panel frontend controller
├── index.php                  # Main dashboard with Basketball Dropzone & media previewers
├── admin.php                  # Admin Panel dashboard
├── stream.php                 # Range-supported streaming proxy for video seeking & downloads
├── share.php                  # Public shareable link viewer page
└── schema.sql                 # MySQL database schema
```