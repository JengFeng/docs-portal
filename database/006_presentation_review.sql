/*
  Presentation preview and review data model.

  Source PPTX files remain read-only. Every preview, annotation, and change
  request is pinned to an immutable source hash. Delivery credentials (for
  example, a Discord bot token) must remain outside SQL Server.

  Run after 005_document_classification_dimensions.sql with a controlled
  migration account. This script is intentionally safe to run more than once.
*/

SET XACT_ABORT ON;
BEGIN TRANSACTION;
GO

IF OBJECT_ID(N'dbo.schema_migrations', N'U') IS NULL
   OR OBJECT_ID(N'dbo.documents', N'U') IS NULL
   OR OBJECT_ID(N'dbo.auth_users', N'U') IS NULL
BEGIN
    ;THROW 51006, 'Run database migrations 001 through 005 before 006_presentation_review.sql.', 1;
END;
GO

IF OBJECT_ID(N'dbo.document_versions', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.document_versions
    (
        version_id BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_document_versions PRIMARY KEY,
        public_id UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_document_versions_public_id DEFAULT (NEWID()),
        document_id BIGINT NOT NULL,
        version_number INT NOT NULL,
        source_content_hash CHAR(64) NOT NULL,
        source_file_size_bytes BIGINT NOT NULL,
        source_modified_at DATETIME2(3) NOT NULL,
        source_relative_path NVARCHAR(512) NOT NULL,
        created_by_user_id BIGINT NULL,
        created_at DATETIME2(3) NOT NULL CONSTRAINT DF_document_versions_created_at DEFAULT (SYSUTCDATETIME()),
        rowver ROWVERSION NOT NULL,
        CONSTRAINT UQ_document_versions_public_id UNIQUE (public_id),
        CONSTRAINT UQ_document_versions_document_number UNIQUE (document_id, version_number),
        CONSTRAINT UQ_document_versions_document_hash UNIQUE (document_id, source_content_hash),
        CONSTRAINT UQ_document_versions_version_document UNIQUE (version_id, document_id),
        CONSTRAINT FK_document_versions_document FOREIGN KEY (document_id) REFERENCES dbo.documents (document_id),
        CONSTRAINT FK_document_versions_created_by FOREIGN KEY (created_by_user_id) REFERENCES dbo.auth_users (user_id),
        CONSTRAINT CK_document_versions_number CHECK (version_number > 0),
        CONSTRAINT CK_document_versions_file_size CHECK (source_file_size_bytes >= 0),
        CONSTRAINT CK_document_versions_hash CHECK (source_content_hash NOT LIKE '%[^0-9A-Fa-f]%'),
        CONSTRAINT CK_document_versions_relative_path CHECK
        (
            source_relative_path NOT LIKE '/%'
            AND source_relative_path NOT LIKE '%..%'
            AND source_relative_path NOT LIKE '%\%'
        )
    );
END;
GO

IF OBJECT_ID(N'dbo.presentation_preview_jobs', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.presentation_preview_jobs
    (
        preview_job_id BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_presentation_preview_jobs PRIMARY KEY,
        public_id UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_presentation_preview_jobs_public_id DEFAULT (NEWID()),
        document_version_id BIGINT NOT NULL,
        job_key CHAR(64) NOT NULL,
        status_code VARCHAR(16) NOT NULL CONSTRAINT DF_presentation_preview_jobs_status DEFAULT ('queued'),
        priority SMALLINT NOT NULL CONSTRAINT DF_presentation_preview_jobs_priority DEFAULT (100),
        attempt_count SMALLINT NOT NULL CONSTRAINT DF_presentation_preview_jobs_attempt_count DEFAULT (0),
        max_attempts SMALLINT NOT NULL CONSTRAINT DF_presentation_preview_jobs_max_attempts DEFAULT (3),
        available_at DATETIME2(3) NOT NULL CONSTRAINT DF_presentation_preview_jobs_available_at DEFAULT (SYSUTCDATETIME()),
        started_at DATETIME2(3) NULL,
        completed_at DATETIME2(3) NULL,
        lease_owner NVARCHAR(128) NULL,
        lease_expires_at DATETIME2(3) NULL,
        options_json NVARCHAR(MAX) NULL,
        error_code VARCHAR(64) NULL,
        error_message NVARCHAR(2000) NULL,
        requested_by_user_id BIGINT NULL,
        created_at DATETIME2(3) NOT NULL CONSTRAINT DF_presentation_preview_jobs_created_at DEFAULT (SYSUTCDATETIME()),
        updated_at DATETIME2(3) NOT NULL CONSTRAINT DF_presentation_preview_jobs_updated_at DEFAULT (SYSUTCDATETIME()),
        rowver ROWVERSION NOT NULL,
        CONSTRAINT UQ_presentation_preview_jobs_public_id UNIQUE (public_id),
        CONSTRAINT UQ_presentation_preview_jobs_job_key UNIQUE (job_key),
        CONSTRAINT UQ_presentation_preview_jobs_job_version UNIQUE (preview_job_id, document_version_id),
        CONSTRAINT FK_presentation_preview_jobs_version FOREIGN KEY (document_version_id) REFERENCES dbo.document_versions (version_id),
        CONSTRAINT FK_presentation_preview_jobs_requested_by FOREIGN KEY (requested_by_user_id) REFERENCES dbo.auth_users (user_id),
        CONSTRAINT CK_presentation_preview_jobs_job_key CHECK (job_key NOT LIKE '%[^0-9A-Fa-f]%'),
        CONSTRAINT CK_presentation_preview_jobs_status CHECK
        (
            status_code IN ('queued', 'processing', 'succeeded', 'failed', 'cancelled')
        ),
        CONSTRAINT CK_presentation_preview_jobs_priority CHECK (priority BETWEEN 0 AND 1000),
        CONSTRAINT CK_presentation_preview_jobs_attempts CHECK
        (
            attempt_count >= 0 AND max_attempts BETWEEN 1 AND 20 AND attempt_count <= max_attempts
        ),
        CONSTRAINT CK_presentation_preview_jobs_options_json CHECK
        (
            options_json IS NULL OR ISJSON(options_json) = 1
        ),
        CONSTRAINT CK_presentation_preview_jobs_lease CHECK
        (
            (lease_owner IS NULL AND lease_expires_at IS NULL)
            OR (lease_owner IS NOT NULL AND lease_expires_at IS NOT NULL)
        )
    );
END;
GO

IF OBJECT_ID(N'dbo.presentation_renditions', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.presentation_renditions
    (
        rendition_id BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_presentation_renditions PRIMARY KEY,
        public_id UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_presentation_renditions_public_id DEFAULT (NEWID()),
        document_version_id BIGINT NOT NULL,
        preview_job_id BIGINT NULL,
        rendition_kind VARCHAR(32) NOT NULL CONSTRAINT DF_presentation_renditions_kind DEFAULT ('pdf'),
        status_code VARCHAR(16) NOT NULL CONSTRAINT DF_presentation_renditions_status DEFAULT ('ready'),
        storage_relative_path NVARCHAR(512) NOT NULL,
        manifest_relative_path NVARCHAR(512) NULL,
        mime_type VARCHAR(100) NOT NULL,
        artifact_sha256 CHAR(64) NOT NULL,
        manifest_sha256 CHAR(64) NULL,
        file_size_bytes BIGINT NOT NULL,
        page_count INT NOT NULL,
        generator_name NVARCHAR(100) NOT NULL,
        generator_version NVARCHAR(64) NULL,
        is_current BIT NOT NULL CONSTRAINT DF_presentation_renditions_is_current DEFAULT (1),
        created_at DATETIME2(3) NOT NULL CONSTRAINT DF_presentation_renditions_created_at DEFAULT (SYSUTCDATETIME()),
        updated_at DATETIME2(3) NOT NULL CONSTRAINT DF_presentation_renditions_updated_at DEFAULT (SYSUTCDATETIME()),
        rowver ROWVERSION NOT NULL,
        CONSTRAINT UQ_presentation_renditions_public_id UNIQUE (public_id),
        CONSTRAINT UQ_presentation_renditions_version_kind_hash UNIQUE
            (document_version_id, rendition_kind, artifact_sha256),
        CONSTRAINT UQ_presentation_renditions_rendition_version UNIQUE
            (rendition_id, document_version_id),
        CONSTRAINT FK_presentation_renditions_version FOREIGN KEY (document_version_id)
            REFERENCES dbo.document_versions (version_id),
        CONSTRAINT FK_presentation_renditions_job_version FOREIGN KEY (preview_job_id, document_version_id)
            REFERENCES dbo.presentation_preview_jobs (preview_job_id, document_version_id),
        CONSTRAINT CK_presentation_renditions_kind CHECK (rendition_kind IN ('pdf', 'slide_images')),
        CONSTRAINT CK_presentation_renditions_status CHECK (status_code IN ('ready', 'stale', 'quarantined')),
        CONSTRAINT CK_presentation_renditions_path CHECK
        (
            storage_relative_path NOT LIKE '/%'
            AND storage_relative_path NOT LIKE '%..%'
            AND storage_relative_path NOT LIKE '%\%'
        ),
        CONSTRAINT CK_presentation_renditions_manifest CHECK
        (
            (manifest_relative_path IS NULL AND manifest_sha256 IS NULL)
            OR
            (
                manifest_relative_path IS NOT NULL
                AND manifest_sha256 IS NOT NULL
                AND manifest_relative_path NOT LIKE '/%'
                AND manifest_relative_path NOT LIKE '%..%'
                AND manifest_relative_path NOT LIKE '%\%'
                AND manifest_sha256 NOT LIKE '%[^0-9A-Fa-f]%'
            )
        ),
        CONSTRAINT CK_presentation_renditions_hash CHECK (artifact_sha256 NOT LIKE '%[^0-9A-Fa-f]%'),
        CONSTRAINT CK_presentation_renditions_size_pages CHECK (file_size_bytes >= 0 AND page_count > 0)
    );
END;
GO

IF OBJECT_ID(N'dbo.presentation_slides', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.presentation_slides
    (
        slide_id BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_presentation_slides PRIMARY KEY,
        rendition_id BIGINT NOT NULL,
        document_version_id BIGINT NOT NULL,
        slide_number INT NOT NULL,
        asset_relative_path NVARCHAR(512) NULL,
        asset_mime_type VARCHAR(100) NULL,
        asset_sha256 CHAR(64) NULL,
        width_pixels INT NULL,
        height_pixels INT NULL,
        extracted_text NVARCHAR(MAX) NULL,
        created_at DATETIME2(3) NOT NULL CONSTRAINT DF_presentation_slides_created_at DEFAULT (SYSUTCDATETIME()),
        rowver ROWVERSION NOT NULL,
        CONSTRAINT UQ_presentation_slides_rendition_number UNIQUE (rendition_id, slide_number),
        CONSTRAINT UQ_presentation_slides_slide_version UNIQUE
            (slide_id, document_version_id),
        CONSTRAINT UQ_presentation_slides_slide_version_hash UNIQUE
            (slide_id, document_version_id, asset_sha256),
        CONSTRAINT FK_presentation_slides_rendition_version FOREIGN KEY (rendition_id, document_version_id)
            REFERENCES dbo.presentation_renditions (rendition_id, document_version_id),
        CONSTRAINT CK_presentation_slides_number CHECK (slide_number > 0),
        CONSTRAINT CK_presentation_slides_asset_completeness CHECK
        (
            (asset_relative_path IS NULL AND asset_mime_type IS NULL AND asset_sha256 IS NULL
                AND width_pixels IS NULL AND height_pixels IS NULL)
            OR
            (asset_relative_path IS NOT NULL AND asset_mime_type IS NOT NULL AND asset_sha256 IS NOT NULL
                AND width_pixels IS NOT NULL AND height_pixels IS NOT NULL)
        ),
        CONSTRAINT CK_presentation_slides_dimensions CHECK
            (width_pixels IS NULL OR (width_pixels > 0 AND height_pixels > 0)),
        CONSTRAINT CK_presentation_slides_asset_mime CHECK
            (asset_mime_type IS NULL OR asset_mime_type IN ('image/png', 'image/jpeg', 'image/webp')),
        CONSTRAINT CK_presentation_slides_asset_hash CHECK
            (asset_sha256 IS NULL OR asset_sha256 NOT LIKE '%[^0-9A-Fa-f]%'),
        CONSTRAINT CK_presentation_slides_asset_path CHECK
        (
            asset_relative_path IS NULL
            OR
            (
                asset_relative_path NOT LIKE '/%'
                AND asset_relative_path NOT LIKE '%..%'
                AND asset_relative_path NOT LIKE '%\%'
            )
        )
    );
END;
GO

IF OBJECT_ID(N'dbo.presentation_annotations', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.presentation_annotations
    (
        annotation_id BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_presentation_annotations PRIMARY KEY,
        public_id UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_presentation_annotations_public_id DEFAULT (NEWID()),
        document_version_id BIGINT NOT NULL,
        slide_id BIGINT NOT NULL,
        slide_asset_sha256 CHAR(64) NULL,
        annotation_type VARCHAR(16) NOT NULL,
        x_norm DECIMAL(9,8) NOT NULL,
        y_norm DECIMAL(9,8) NOT NULL,
        width_norm DECIMAL(9,8) NOT NULL,
        height_norm DECIMAL(9,8) NOT NULL,
        geometry_json NVARCHAR(MAX) NOT NULL,
        style_json NVARCHAR(MAX) NULL,
        comment_text NVARCHAR(2000) NULL,
        status_code VARCHAR(16) NOT NULL CONSTRAINT DF_presentation_annotations_status DEFAULT ('open'),
        created_by_user_id BIGINT NOT NULL,
        updated_by_user_id BIGINT NOT NULL,
        resolved_by_user_id BIGINT NULL,
        created_at DATETIME2(3) NOT NULL CONSTRAINT DF_presentation_annotations_created_at DEFAULT (SYSUTCDATETIME()),
        updated_at DATETIME2(3) NOT NULL CONSTRAINT DF_presentation_annotations_updated_at DEFAULT (SYSUTCDATETIME()),
        resolved_at DATETIME2(3) NULL,
        rowver ROWVERSION NOT NULL,
        CONSTRAINT UQ_presentation_annotations_public_id UNIQUE (public_id),
        CONSTRAINT UQ_presentation_annotations_source_version UNIQUE
            (annotation_id, document_version_id, slide_id),
        CONSTRAINT UQ_presentation_annotations_source_snapshot UNIQUE
            (annotation_id, document_version_id, slide_id, slide_asset_sha256),
        CONSTRAINT FK_presentation_annotations_slide_version
            FOREIGN KEY (slide_id, document_version_id)
            REFERENCES dbo.presentation_slides (slide_id, document_version_id),
        CONSTRAINT FK_presentation_annotations_slide_version_hash
            FOREIGN KEY (slide_id, document_version_id, slide_asset_sha256)
            REFERENCES dbo.presentation_slides (slide_id, document_version_id, asset_sha256),
        CONSTRAINT FK_presentation_annotations_created_by FOREIGN KEY (created_by_user_id)
            REFERENCES dbo.auth_users (user_id),
        CONSTRAINT FK_presentation_annotations_updated_by FOREIGN KEY (updated_by_user_id)
            REFERENCES dbo.auth_users (user_id),
        CONSTRAINT FK_presentation_annotations_resolved_by FOREIGN KEY (resolved_by_user_id)
            REFERENCES dbo.auth_users (user_id),
        CONSTRAINT CK_presentation_annotations_type CHECK
            (annotation_type IN ('rectangle', 'ellipse', 'arrow', 'highlight', 'text', 'freehand', 'marker')),
        CONSTRAINT CK_presentation_annotations_status CHECK
            (status_code IN ('open', 'resolved', 'withdrawn')),
        CONSTRAINT CK_presentation_annotations_geometry_json CHECK (ISJSON(geometry_json) = 1),
        CONSTRAINT CK_presentation_annotations_style_json CHECK
            (style_json IS NULL OR ISJSON(style_json) = 1),
        CONSTRAINT CK_presentation_annotations_bounds CHECK
        (
            x_norm >= 0 AND x_norm <= 1
            AND y_norm >= 0 AND y_norm <= 1
            AND width_norm >= 0 AND height_norm >= 0
            AND x_norm + width_norm <= 1
            AND y_norm + height_norm <= 1
            AND
            (
                (annotation_type = 'arrow' AND (width_norm > 0 OR height_norm > 0))
                OR (annotation_type <> 'arrow' AND width_norm > 0 AND height_norm > 0)
            )
        ),
        CONSTRAINT CK_presentation_annotations_resolution CHECK
        (
            status_code <> 'resolved'
            OR (resolved_by_user_id IS NOT NULL AND resolved_at IS NOT NULL)
        )
    );
END;
GO

IF OBJECT_ID(N'dbo.presentation_change_requests', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.presentation_change_requests
    (
        change_request_id BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_presentation_change_requests PRIMARY KEY,
        public_id UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_presentation_change_requests_public_id DEFAULT (NEWID()),
        document_version_id BIGINT NOT NULL,
        title NVARCHAR(255) NOT NULL,
        request_text NVARCHAR(4000) NULL,
        status_code VARCHAR(16) NOT NULL CONSTRAINT DF_presentation_change_requests_status DEFAULT ('draft'),
        created_by_user_id BIGINT NOT NULL,
        updated_by_user_id BIGINT NOT NULL,
        submitted_by_user_id BIGINT NULL,
        completed_by_user_id BIGINT NULL,
        created_at DATETIME2(3) NOT NULL CONSTRAINT DF_presentation_change_requests_created_at DEFAULT (SYSUTCDATETIME()),
        updated_at DATETIME2(3) NOT NULL CONSTRAINT DF_presentation_change_requests_updated_at DEFAULT (SYSUTCDATETIME()),
        submitted_at DATETIME2(3) NULL,
        completed_at DATETIME2(3) NULL,
        rowver ROWVERSION NOT NULL,
        CONSTRAINT UQ_presentation_change_requests_public_id UNIQUE (public_id),
        CONSTRAINT UQ_presentation_change_requests_request_version UNIQUE
            (change_request_id, document_version_id),
        CONSTRAINT FK_presentation_change_requests_version FOREIGN KEY (document_version_id)
            REFERENCES dbo.document_versions (version_id),
        CONSTRAINT FK_presentation_change_requests_created_by FOREIGN KEY (created_by_user_id)
            REFERENCES dbo.auth_users (user_id),
        CONSTRAINT FK_presentation_change_requests_updated_by FOREIGN KEY (updated_by_user_id)
            REFERENCES dbo.auth_users (user_id),
        CONSTRAINT FK_presentation_change_requests_submitted_by FOREIGN KEY (submitted_by_user_id)
            REFERENCES dbo.auth_users (user_id),
        CONSTRAINT FK_presentation_change_requests_completed_by FOREIGN KEY (completed_by_user_id)
            REFERENCES dbo.auth_users (user_id),
        CONSTRAINT CK_presentation_change_requests_title CHECK (LEN(LTRIM(RTRIM(title))) BETWEEN 1 AND 255),
        CONSTRAINT CK_presentation_change_requests_status CHECK
        (
            status_code IN ('draft', 'ready', 'submitted', 'in_review', 'completed', 'cancelled', 'superseded')
        ),
        CONSTRAINT CK_presentation_change_requests_submission CHECK
        (
            status_code NOT IN ('submitted', 'in_review', 'completed')
            OR (submitted_by_user_id IS NOT NULL AND submitted_at IS NOT NULL)
        ),
        CONSTRAINT CK_presentation_change_requests_completion CHECK
        (
            status_code <> 'completed'
            OR (completed_by_user_id IS NOT NULL AND completed_at IS NOT NULL)
        )
    );
END;
GO

IF OBJECT_ID(N'dbo.presentation_change_request_items', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.presentation_change_request_items
    (
        change_request_item_id BIGINT IDENTITY(1,1) NOT NULL
            CONSTRAINT PK_presentation_change_request_items PRIMARY KEY,
        change_request_id BIGINT NOT NULL,
        document_version_id BIGINT NOT NULL,
        slide_id BIGINT NOT NULL,
        slide_asset_sha256 CHAR(64) NULL,
        source_annotation_id BIGINT NULL,
        item_order INT NOT NULL,
        annotation_type_snapshot VARCHAR(16) NOT NULL,
        geometry_json_snapshot NVARCHAR(MAX) NOT NULL,
        style_json_snapshot NVARCHAR(MAX) NULL,
        comment_text_snapshot NVARCHAR(2000) NULL,
        instruction_text NVARCHAR(4000) NOT NULL,
        status_code VARCHAR(16) NOT NULL CONSTRAINT DF_presentation_change_request_items_status DEFAULT ('included'),
        captured_at DATETIME2(3) NOT NULL CONSTRAINT DF_presentation_change_request_items_captured_at DEFAULT (SYSUTCDATETIME()),
        rowver ROWVERSION NOT NULL,
        CONSTRAINT UQ_presentation_change_request_items_order UNIQUE (change_request_id, item_order),
        CONSTRAINT FK_presentation_change_request_items_request_version
            FOREIGN KEY (change_request_id, document_version_id)
            REFERENCES dbo.presentation_change_requests (change_request_id, document_version_id),
        CONSTRAINT FK_presentation_change_request_items_slide_version
            FOREIGN KEY (slide_id, document_version_id)
            REFERENCES dbo.presentation_slides (slide_id, document_version_id),
        CONSTRAINT FK_presentation_change_request_items_slide_version_hash
            FOREIGN KEY (slide_id, document_version_id, slide_asset_sha256)
            REFERENCES dbo.presentation_slides (slide_id, document_version_id, asset_sha256),
        CONSTRAINT FK_presentation_change_request_items_annotation_version
            FOREIGN KEY (source_annotation_id, document_version_id, slide_id)
            REFERENCES dbo.presentation_annotations
                (annotation_id, document_version_id, slide_id),
        CONSTRAINT FK_presentation_change_request_items_annotation_snapshot
            FOREIGN KEY (source_annotation_id, document_version_id, slide_id, slide_asset_sha256)
            REFERENCES dbo.presentation_annotations
                (annotation_id, document_version_id, slide_id, slide_asset_sha256),
        CONSTRAINT CK_presentation_change_request_items_order CHECK (item_order > 0),
        CONSTRAINT CK_presentation_change_request_items_type CHECK
            (annotation_type_snapshot IN ('rectangle', 'ellipse', 'arrow', 'highlight', 'text', 'freehand', 'marker')),
        CONSTRAINT CK_presentation_change_request_items_geometry_json CHECK
            (ISJSON(geometry_json_snapshot) = 1),
        CONSTRAINT CK_presentation_change_request_items_style_json CHECK
            (style_json_snapshot IS NULL OR ISJSON(style_json_snapshot) = 1),
        CONSTRAINT CK_presentation_change_request_items_instruction CHECK
            (LEN(LTRIM(RTRIM(instruction_text))) BETWEEN 1 AND 4000),
        CONSTRAINT CK_presentation_change_request_items_status CHECK
            (status_code IN ('included', 'removed'))
    );
END;
GO

IF OBJECT_ID(N'dbo.presentation_request_dispatches', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.presentation_request_dispatches
    (
        dispatch_id BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_presentation_request_dispatches PRIMARY KEY,
        public_id UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_presentation_request_dispatches_public_id DEFAULT (NEWID()),
        change_request_id BIGINT NOT NULL,
        channel_code VARCHAR(24) NOT NULL,
        destination_reference NVARCHAR(256) NULL,
        idempotency_key CHAR(64) NOT NULL,
        payload_sha256 CHAR(64) NOT NULL,
        payload_json NVARCHAR(MAX) NOT NULL,
        status_code VARCHAR(16) NOT NULL CONSTRAINT DF_presentation_request_dispatches_status DEFAULT ('queued'),
        attempt_count SMALLINT NOT NULL CONSTRAINT DF_presentation_request_dispatches_attempt_count DEFAULT (0),
        max_attempts SMALLINT NOT NULL CONSTRAINT DF_presentation_request_dispatches_max_attempts DEFAULT (5),
        next_attempt_at DATETIME2(3) NOT NULL CONSTRAINT DF_presentation_request_dispatches_next_attempt DEFAULT (SYSUTCDATETIME()),
        lease_owner NVARCHAR(128) NULL,
        lease_expires_at DATETIME2(3) NULL,
        message_reference NVARCHAR(256) NULL,
        last_error_code VARCHAR(64) NULL,
        last_error_message NVARCHAR(2000) NULL,
        created_at DATETIME2(3) NOT NULL CONSTRAINT DF_presentation_request_dispatches_created_at DEFAULT (SYSUTCDATETIME()),
        updated_at DATETIME2(3) NOT NULL CONSTRAINT DF_presentation_request_dispatches_updated_at DEFAULT (SYSUTCDATETIME()),
        sent_at DATETIME2(3) NULL,
        rowver ROWVERSION NOT NULL,
        CONSTRAINT UQ_presentation_request_dispatches_public_id UNIQUE (public_id),
        CONSTRAINT UQ_presentation_request_dispatches_idempotency UNIQUE (idempotency_key),
        CONSTRAINT FK_presentation_request_dispatches_request FOREIGN KEY (change_request_id)
            REFERENCES dbo.presentation_change_requests (change_request_id),
        CONSTRAINT CK_presentation_request_dispatches_channel CHECK
            (channel_code IN ('manual_export', 'discord')),
        CONSTRAINT CK_presentation_request_dispatches_idempotency CHECK
            (idempotency_key NOT LIKE '%[^0-9A-Fa-f]%'),
        CONSTRAINT CK_presentation_request_dispatches_payload_hash CHECK
            (payload_sha256 NOT LIKE '%[^0-9A-Fa-f]%'),
        CONSTRAINT CK_presentation_request_dispatches_payload_json CHECK (ISJSON(payload_json) = 1),
        CONSTRAINT CK_presentation_request_dispatches_status CHECK
        (
            status_code IN ('queued', 'processing', 'sent', 'failed', 'dead_letter', 'cancelled')
        ),
        CONSTRAINT CK_presentation_request_dispatches_attempts CHECK
        (
            attempt_count >= 0 AND max_attempts BETWEEN 1 AND 20 AND attempt_count <= max_attempts
        ),
        CONSTRAINT CK_presentation_request_dispatches_lease CHECK
        (
            (lease_owner IS NULL AND lease_expires_at IS NULL)
            OR (lease_owner IS NOT NULL AND lease_expires_at IS NOT NULL)
        ),
        CONSTRAINT CK_presentation_request_dispatches_sent CHECK
            (status_code <> 'sent' OR sent_at IS NOT NULL)
    );
END;
GO

IF NOT EXISTS
(
    SELECT 1 FROM sys.indexes
    WHERE name = N'IX_document_versions_document_created'
      AND object_id = OBJECT_ID(N'dbo.document_versions')
)
    CREATE INDEX IX_document_versions_document_created
        ON dbo.document_versions (document_id, version_number DESC, created_at DESC)
        INCLUDE (public_id, source_content_hash, source_modified_at);
GO

IF NOT EXISTS
(
    SELECT 1 FROM sys.indexes
    WHERE name = N'IX_presentation_preview_jobs_queue'
      AND object_id = OBJECT_ID(N'dbo.presentation_preview_jobs')
)
    CREATE INDEX IX_presentation_preview_jobs_queue
        ON dbo.presentation_preview_jobs (status_code, available_at, priority, created_at)
        INCLUDE (document_version_id, attempt_count, max_attempts, lease_expires_at);
GO

IF NOT EXISTS
(
    SELECT 1 FROM sys.indexes
    WHERE name = N'IX_presentation_renditions_version_status'
      AND object_id = OBJECT_ID(N'dbo.presentation_renditions')
)
    CREATE INDEX IX_presentation_renditions_version_status
        ON dbo.presentation_renditions (document_version_id, rendition_kind, status_code, created_at DESC)
        INCLUDE (public_id, storage_relative_path, artifact_sha256, page_count, is_current);
GO

IF NOT EXISTS
(
    SELECT 1 FROM sys.indexes
    WHERE name = N'UX_presentation_renditions_current'
      AND object_id = OBJECT_ID(N'dbo.presentation_renditions')
)
    CREATE UNIQUE INDEX UX_presentation_renditions_current
        ON dbo.presentation_renditions (document_version_id, rendition_kind)
        WHERE is_current = 1;
GO

IF NOT EXISTS
(
    SELECT 1 FROM sys.indexes
    WHERE name = N'IX_presentation_slides_version_number'
      AND object_id = OBJECT_ID(N'dbo.presentation_slides')
)
    CREATE INDEX IX_presentation_slides_version_number
        ON dbo.presentation_slides (document_version_id, slide_number)
        INCLUDE (slide_id, rendition_id, asset_relative_path, asset_sha256, width_pixels, height_pixels);
GO

IF NOT EXISTS
(
    SELECT 1 FROM sys.indexes
    WHERE name = N'IX_presentation_annotations_slide_status'
      AND object_id = OBJECT_ID(N'dbo.presentation_annotations')
)
    CREATE INDEX IX_presentation_annotations_slide_status
        ON dbo.presentation_annotations (slide_id, status_code, updated_at DESC)
        INCLUDE (public_id, document_version_id, annotation_type, created_by_user_id);
GO

IF NOT EXISTS
(
    SELECT 1 FROM sys.indexes
    WHERE name = N'IX_presentation_change_requests_version_status'
      AND object_id = OBJECT_ID(N'dbo.presentation_change_requests')
)
    CREATE INDEX IX_presentation_change_requests_version_status
        ON dbo.presentation_change_requests (document_version_id, status_code, updated_at DESC)
        INCLUDE (public_id, title, created_by_user_id);
GO

IF NOT EXISTS
(
    SELECT 1 FROM sys.indexes
    WHERE name = N'IX_presentation_change_request_items_annotation'
      AND object_id = OBJECT_ID(N'dbo.presentation_change_request_items')
)
    CREATE UNIQUE INDEX IX_presentation_change_request_items_annotation
        ON dbo.presentation_change_request_items (change_request_id, source_annotation_id)
        WHERE source_annotation_id IS NOT NULL;
GO

IF NOT EXISTS
(
    SELECT 1 FROM sys.indexes
    WHERE name = N'IX_presentation_request_dispatches_queue'
      AND object_id = OBJECT_ID(N'dbo.presentation_request_dispatches')
)
    CREATE INDEX IX_presentation_request_dispatches_queue
        ON dbo.presentation_request_dispatches (status_code, next_attempt_at, created_at)
        INCLUDE (change_request_id, channel_code, attempt_count, max_attempts, lease_expires_at);
GO

IF NOT EXISTS
(
    SELECT 1 FROM sys.indexes
    WHERE name = N'IX_presentation_request_dispatches_request'
      AND object_id = OBJECT_ID(N'dbo.presentation_request_dispatches')
)
    CREATE INDEX IX_presentation_request_dispatches_request
        ON dbo.presentation_request_dispatches (change_request_id, created_at DESC)
        INCLUDE (public_id, channel_code, status_code, message_reference);
GO

/* Backfill one immutable version for each hash-bearing document. */
INSERT INTO dbo.document_versions
(
    document_id,
    version_number,
    source_content_hash,
    source_file_size_bytes,
    source_modified_at,
    source_relative_path,
    created_by_user_id
)
SELECT
    d.document_id,
    ISNULL(existing_version.max_version_number, 0) + 1,
    d.content_hash,
    d.file_size_bytes,
    d.source_modified_at,
    d.relative_path,
    COALESCE(d.updated_by_user_id, d.created_by_user_id)
FROM dbo.documents AS d
OUTER APPLY
(
    SELECT MAX(v.version_number) AS max_version_number
    FROM dbo.document_versions AS v
    WHERE v.document_id = d.document_id
) AS existing_version
WHERE d.extension = 'pptx'
  AND d.content_hash IS NOT NULL
  AND NOT EXISTS
      (
          SELECT 1
          FROM dbo.document_versions AS v
          WHERE v.document_id = d.document_id
            AND v.source_content_hash = d.content_hash
      );
GO

/* Queue the initial preview for each current, hash-bearing PPTX version. */
INSERT INTO dbo.presentation_preview_jobs
(
    document_version_id,
    job_key,
    status_code,
    requested_by_user_id,
    options_json
)
SELECT
    v.version_id,
    LOWER(CONVERT(CHAR(64), HASHBYTES
    (
        'SHA2_256',
        CONCAT('initial-pptx-preview|', CONVERT(VARCHAR(30), v.version_id), '|', v.source_content_hash)
    ), 2)),
    'queued',
    COALESCE(d.updated_by_user_id, d.created_by_user_id),
    N'{"rendition":"pdf","slideAssets":false}'
FROM dbo.documents AS d
INNER JOIN dbo.document_versions AS v
    ON v.document_id = d.document_id
   AND v.source_content_hash = d.content_hash
WHERE d.extension = 'pptx'
  AND d.content_hash IS NOT NULL
  AND NOT EXISTS
      (
          SELECT 1
          FROM dbo.presentation_preview_jobs AS j
          WHERE j.job_key = LOWER(CONVERT(CHAR(64), HASHBYTES
          (
              'SHA2_256',
              CONCAT('initial-pptx-preview|', CONVERT(VARCHAR(30), v.version_id), '|', v.source_content_hash)
          ), 2))
      );
GO

/* Apply least-scope table permissions when the application principal exists. */
IF DATABASE_PRINCIPAL_ID(N'TWWATER_PORTAL_APP') IS NOT NULL
BEGIN
    GRANT SELECT, INSERT ON dbo.document_versions TO [TWWATER_PORTAL_APP];
    GRANT SELECT, INSERT, UPDATE ON dbo.presentation_preview_jobs TO [TWWATER_PORTAL_APP];
    GRANT SELECT, INSERT, UPDATE ON dbo.presentation_renditions TO [TWWATER_PORTAL_APP];
    GRANT SELECT, INSERT, UPDATE ON dbo.presentation_slides TO [TWWATER_PORTAL_APP];
    GRANT SELECT, INSERT, UPDATE ON dbo.presentation_annotations TO [TWWATER_PORTAL_APP];
    GRANT SELECT, INSERT, UPDATE ON dbo.presentation_change_requests TO [TWWATER_PORTAL_APP];
    GRANT SELECT, INSERT, UPDATE ON dbo.presentation_change_request_items TO [TWWATER_PORTAL_APP];
    GRANT SELECT, INSERT, UPDATE ON dbo.presentation_request_dispatches TO [TWWATER_PORTAL_APP];

    /* Runtime workflows use soft state transitions. Physical deletion of
       version, rendition, annotation, request, and dispatch history is never
       required by the web application or preview worker. */
    DENY UPDATE, DELETE ON dbo.document_versions TO [TWWATER_PORTAL_APP];
    DENY DELETE ON dbo.presentation_preview_jobs TO [TWWATER_PORTAL_APP];
    DENY DELETE ON dbo.presentation_renditions TO [TWWATER_PORTAL_APP];
    DENY DELETE ON dbo.presentation_slides TO [TWWATER_PORTAL_APP];
    DENY DELETE ON dbo.presentation_annotations TO [TWWATER_PORTAL_APP];
    DENY DELETE ON dbo.presentation_change_requests TO [TWWATER_PORTAL_APP];
    DENY DELETE ON dbo.presentation_change_request_items TO [TWWATER_PORTAL_APP];
    DENY DELETE ON dbo.presentation_request_dispatches TO [TWWATER_PORTAL_APP];
END;
GO

IF NOT EXISTS
(
    SELECT 1
    FROM dbo.schema_migrations
    WHERE script_name = N'006_presentation_review.sql'
)
    INSERT INTO dbo.schema_migrations (script_name, checksum_sha256)
    VALUES (N'006_presentation_review.sql', NULL);
GO

COMMIT TRANSACTION;
GO
