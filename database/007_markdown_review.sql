/*
  Adds an immutable browser-rendered Markdown review surface.
  The source .md file remains read-only; annotations and change requests stay
  pinned to its SHA-256 document version.
*/

SET NOCOUNT ON;
SET XACT_ABORT ON;
GO

IF OBJECT_ID(N'dbo.presentation_renditions', N'U') IS NULL
    THROW 50001, 'Migration 006 must be applied before 007.', 1;
GO

IF EXISTS
(
    SELECT 1
    FROM sys.check_constraints
    WHERE parent_object_id = OBJECT_ID(N'dbo.presentation_renditions')
      AND name = N'CK_presentation_renditions_kind'
)
    ALTER TABLE dbo.presentation_renditions
        DROP CONSTRAINT CK_presentation_renditions_kind;
GO

ALTER TABLE dbo.presentation_renditions WITH CHECK
    ADD CONSTRAINT CK_presentation_renditions_kind
        CHECK (rendition_kind IN ('pdf', 'slide_images', 'markdown'));
GO

ALTER TABLE dbo.presentation_renditions
    CHECK CONSTRAINT CK_presentation_renditions_kind;
GO
