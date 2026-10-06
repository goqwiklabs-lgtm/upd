export interface User {
  id: number;
  username: string;
  email: string;
  role: 'user' | 'admin';
}

export interface FolderItem {
  id: number;
  user_id: number;
  parent_id: number | null;
  name: string;
  created_at: string;
}

export interface FileItem {
  id: number;
  user_id: number;
  folder_id: number | null;
  google_account_id: number;
  google_file_id: string;
  name: string;
  size_bytes: number;
  mime_type: string;
  share_token: string;
  is_public: number;
  created_at: string;
}

export interface GoogleAccount {
  id: number;
  account_email: string;
  client_id: string;
  used_storage_bytes: number;
  storage_limit_bytes: number;
  total_capacity_bytes?: number;
  initial_used_bytes?: number;
  drive_folder_id?: string;
  is_active: number;
  created_at: string;
  files_count?: number;
}

export interface BreadcrumbItem {
  id: number;
  name: string;
}

export interface UploadItem {
  id: string;
  name: string;
  size: number;
  progress: number;
  status: 'connecting' | 'uploading' | 'completed' | 'error';
  errorMessage?: string;
}
