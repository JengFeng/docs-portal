/* Typed image annotation tools for the full-width visual asset reviewer.
   Run after 014_image_asset_library.sql with the controlled migration identity. */
SET XACT_ABORT ON;
BEGIN TRANSACTION;
GO

IF OBJECT_ID(N'dbo.image_annotations', N'U') IS NULL
BEGIN
    THROW 51015, 'Run 014_image_asset_library.sql before 015_image_annotation_tools.sql.', 1;
END;
GO

IF COL_LENGTH(N'dbo.image_annotations', N'annotation_type_code') IS NULL
    ALTER TABLE dbo.image_annotations ADD annotation_type_code VARCHAR(20) NOT NULL
        CONSTRAINT DF_image_annotations_type DEFAULT ('rectangle');
GO
IF COL_LENGTH(N'dbo.image_annotations', N'geometry_json') IS NULL
    ALTER TABLE dbo.image_annotations ADD geometry_json NVARCHAR(4000) NOT NULL
        CONSTRAINT DF_image_annotations_geometry DEFAULT (N'{}');
GO
IF COL_LENGTH(N'dbo.image_annotations', N'style_json') IS NULL
    ALTER TABLE dbo.image_annotations ADD style_json NVARCHAR(2000) NOT NULL
        CONSTRAINT DF_image_annotations_style DEFAULT (N'{}');
GO

IF NOT EXISTS (SELECT 1 FROM sys.check_constraints WHERE name = N'CK_image_annotations_type' AND parent_object_id = OBJECT_ID(N'dbo.image_annotations'))
    ALTER TABLE dbo.image_annotations WITH CHECK ADD CONSTRAINT CK_image_annotations_type CHECK
        (annotation_type_code IN ('rectangle','arrow','highlight','text','number'));
GO
IF NOT EXISTS (SELECT 1 FROM sys.check_constraints WHERE name = N'CK_image_annotations_geometry_json' AND parent_object_id = OBJECT_ID(N'dbo.image_annotations'))
    ALTER TABLE dbo.image_annotations WITH CHECK ADD CONSTRAINT CK_image_annotations_geometry_json CHECK
        (ISJSON(geometry_json) = 1 AND LEN(geometry_json) <= 4000);
GO
IF NOT EXISTS (SELECT 1 FROM sys.check_constraints WHERE name = N'CK_image_annotations_style_json' AND parent_object_id = OBJECT_ID(N'dbo.image_annotations'))
    ALTER TABLE dbo.image_annotations WITH CHECK ADD CONSTRAINT CK_image_annotations_style_json CHECK
        (ISJSON(style_json) = 1 AND LEN(style_json) <= 2000);
GO

IF NOT EXISTS (SELECT 1 FROM dbo.schema_migrations WHERE script_name = N'015_image_annotation_tools.sql')
    INSERT INTO dbo.schema_migrations(script_name, checksum_sha256) VALUES (N'015_image_annotation_tools.sql', NULL);
GO
COMMIT TRANSACTION;
GO
