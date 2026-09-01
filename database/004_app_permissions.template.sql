/*
  以 migration 帳號執行前，先把 [TWWATER_PORTAL_APP] 換成實際「資料庫使用者」名稱。
  此範本不建立 Login，也不授予 DDL/db_owner 權限。
*/

-- 範例：CREATE USER [TWWATER_PORTAL_APP] FOR LOGIN [TWWATER_PORTAL_APP];

GRANT SELECT, INSERT, UPDATE ON dbo.auth_users TO [TWWATER_PORTAL_APP];
GRANT SELECT, INSERT, UPDATE, DELETE ON dbo.auth_rate_limits TO [TWWATER_PORTAL_APP];
GRANT SELECT, INSERT ON dbo.auth_audit_events TO [TWWATER_PORTAL_APP];

IF OBJECT_ID(N'dbo.image_annotations', N'U') IS NOT NULL
    GRANT SELECT, INSERT, UPDATE, DELETE ON dbo.image_annotations TO [TWWATER_PORTAL_APP];
IF OBJECT_ID(N'dbo.image_requirement_revisions', N'U') IS NOT NULL
BEGIN
    GRANT SELECT, INSERT, UPDATE ON dbo.image_requirement_revisions TO [TWWATER_PORTAL_APP];
    DENY DELETE ON dbo.image_requirement_revisions TO [TWWATER_PORTAL_APP];
END;

GRANT SELECT ON dbo.document_types TO [TWWATER_PORTAL_APP];
GRANT SELECT ON dbo.ssdlc_phases TO [TWWATER_PORTAL_APP];
GRANT SELECT, INSERT, UPDATE ON dbo.documents TO [TWWATER_PORTAL_APP];
GRANT SELECT, INSERT, DELETE ON dbo.document_phase_roles TO [TWWATER_PORTAL_APP];
IF OBJECT_ID(N'dbo.document_type_links', N'U') IS NOT NULL
    GRANT SELECT, INSERT, DELETE ON dbo.document_type_links TO [TWWATER_PORTAL_APP];
GRANT SELECT, INSERT, UPDATE ON dbo.document_sync_runs TO [TWWATER_PORTAL_APP];
GRANT SELECT, INSERT ON dbo.document_audit_events TO [TWWATER_PORTAL_APP];

IF OBJECT_ID(N'dbo.document_versions', N'U') IS NOT NULL
BEGIN
    GRANT SELECT, INSERT ON dbo.document_versions TO [TWWATER_PORTAL_APP];
    DENY UPDATE, DELETE ON dbo.document_versions TO [TWWATER_PORTAL_APP];
END;
IF OBJECT_ID(N'dbo.presentation_preview_jobs', N'U') IS NOT NULL
BEGIN
    GRANT SELECT, INSERT, UPDATE ON dbo.presentation_preview_jobs TO [TWWATER_PORTAL_APP];
    DENY DELETE ON dbo.presentation_preview_jobs TO [TWWATER_PORTAL_APP];
END;
IF OBJECT_ID(N'dbo.presentation_renditions', N'U') IS NOT NULL
BEGIN
    GRANT SELECT, INSERT, UPDATE ON dbo.presentation_renditions TO [TWWATER_PORTAL_APP];
    DENY DELETE ON dbo.presentation_renditions TO [TWWATER_PORTAL_APP];
END;
IF OBJECT_ID(N'dbo.presentation_slides', N'U') IS NOT NULL
BEGIN
    GRANT SELECT, INSERT, UPDATE ON dbo.presentation_slides TO [TWWATER_PORTAL_APP];
    DENY DELETE ON dbo.presentation_slides TO [TWWATER_PORTAL_APP];
END;
IF OBJECT_ID(N'dbo.presentation_annotations', N'U') IS NOT NULL
BEGIN
    GRANT SELECT, INSERT, UPDATE ON dbo.presentation_annotations TO [TWWATER_PORTAL_APP];
    DENY DELETE ON dbo.presentation_annotations TO [TWWATER_PORTAL_APP];
END;
IF OBJECT_ID(N'dbo.presentation_change_requests', N'U') IS NOT NULL
BEGIN
    GRANT SELECT, INSERT, UPDATE ON dbo.presentation_change_requests TO [TWWATER_PORTAL_APP];
    DENY DELETE ON dbo.presentation_change_requests TO [TWWATER_PORTAL_APP];
END;
IF OBJECT_ID(N'dbo.presentation_change_request_items', N'U') IS NOT NULL
BEGIN
    GRANT SELECT, INSERT, UPDATE ON dbo.presentation_change_request_items TO [TWWATER_PORTAL_APP];
    DENY DELETE ON dbo.presentation_change_request_items TO [TWWATER_PORTAL_APP];
END;
IF OBJECT_ID(N'dbo.presentation_request_dispatches', N'U') IS NOT NULL
BEGIN
    GRANT SELECT, INSERT, UPDATE ON dbo.presentation_request_dispatches TO [TWWATER_PORTAL_APP];
    DENY DELETE ON dbo.presentation_request_dispatches TO [TWWATER_PORTAL_APP];
END;

IF OBJECT_ID(N'dbo.staged_document_revisions', N'U') IS NOT NULL
BEGIN
    GRANT SELECT, INSERT, UPDATE ON dbo.staged_document_revisions TO [TWWATER_PORTAL_APP];
    DENY DELETE ON dbo.staged_document_revisions TO [TWWATER_PORTAL_APP];
END;

IF OBJECT_ID(N'dbo.site_feedback_batches', N'U') IS NOT NULL
BEGIN
    GRANT SELECT, INSERT, UPDATE ON dbo.site_feedback_batches TO [TWWATER_PORTAL_APP];
    DENY DELETE ON dbo.site_feedback_batches TO [TWWATER_PORTAL_APP];
END;
IF OBJECT_ID(N'dbo.site_feedback_items', N'U') IS NOT NULL
BEGIN
    GRANT SELECT, INSERT, UPDATE ON dbo.site_feedback_items TO [TWWATER_PORTAL_APP];
    DENY DELETE ON dbo.site_feedback_items TO [TWWATER_PORTAL_APP];
END;
IF OBJECT_ID(N'dbo.site_feedback_dispatches', N'U') IS NOT NULL
BEGIN
    GRANT SELECT, INSERT, UPDATE ON dbo.site_feedback_dispatches TO [TWWATER_PORTAL_APP];
    DENY DELETE ON dbo.site_feedback_dispatches TO [TWWATER_PORTAL_APP];
END;
IF OBJECT_ID(N'dbo.site_feedback_events', N'U') IS NOT NULL
BEGIN
    GRANT SELECT, INSERT ON dbo.site_feedback_events TO [TWWATER_PORTAL_APP];
    DENY UPDATE, DELETE ON dbo.site_feedback_events TO [TWWATER_PORTAL_APP];
END;
IF OBJECT_ID(N'dbo.site_feedback_snapshots', N'U') IS NOT NULL
BEGIN
    GRANT SELECT, INSERT ON dbo.site_feedback_snapshots TO [TWWATER_PORTAL_APP];
    DENY UPDATE, DELETE ON dbo.site_feedback_snapshots TO [TWWATER_PORTAL_APP];
END;

DENY UPDATE, DELETE ON dbo.auth_audit_events TO [TWWATER_PORTAL_APP];
DENY UPDATE, DELETE ON dbo.document_audit_events TO [TWWATER_PORTAL_APP];
