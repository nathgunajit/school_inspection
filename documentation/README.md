# Technical Documentation: School Inspection Management System

These documents were prepared on 19-11-2026, as asked in `Documents/Documentation.docx`. They are based on a read-only review of the source code, the migrations, the live database schema (structure only, no row data) and the UI. No application code, data or configuration was changed.

A combined Word version of all chapters is in `School_Inspection_Technical_Documentation.docx`.

| # | Document | For | Contents |
|---|---|---|---|
| 1 | [Database_Architecture_and_Schema.md](Database_Architecture_and_Schema.md) | Developers, DBA | 38 tables, every column, keys, relations, ER diagram, business logic |
| 2 | [Project_File_Structure_and_Technical_Architecture.md](Project_File_Structure_and_Technical_Architecture.md) | Developers | Stack, folders, file inventory, backend/frontend architecture, routes, commands |
| 3 | [School_Inspection_Mechanism.md](School_Inspection_Mechanism.md) | Everyone | Users, schools, planning, the full questionnaire, workflow, rating, reports |
| 4 | [User_Roles_and_Permissions.md](User_Roles_and_Permissions.md) | Admins, developers | Levels, permissions matrix, approval chain, scoping, login, menu visibility |
| 5 | [UI_Pages_and_Application_Modules.md](UI_Pages_and_Application_Modules.md) | Developers, testers | Each page with route, controller, view and role; design system |
| 6 | [System_Architecture_and_Workflow.md](System_Architecture_and_Workflow.md) | Architects | 8 Mermaid diagrams: architecture, ER, access, hierarchy, workflows, data flow, modules |
| 7 | [Technical_Audit_and_Recommendations.md](Technical_Audit_and_Recommendations.md) | Management, developers | Security, DB, code, performance observations; inconsistencies; fixes |
| 8 | [Future_Recommendations_Gaps_and_Roadmap.md](Future_Recommendations_Gaps_and_Roadmap.md) | Management | Functional and technical gaps, technology choices, phased roadmap |

**Status labels:** **Implemented** means it works in the current code. **Partial** means it is present but limited, or depends on configuration. **Not implemented** means it is absent (often planned for Phase 2).

**Reading order.** New developers: 2 → 1 → 3 → 4 → 5. Management: 3 → 7 → 8. Testers: 3 → 5 → 4.

**Other project documents:**
- `CLAUDE.md`: developer rules
- `PROJECT_DOCUMENT.md`: functional document with the V6/V7 changes
- `INSPECTION_FORM_DOCUMENT.md`: form details with screenshots
- `DB_INDEXING.md`: indexes
- `LOAD_TEST.md`: capacity
- `BROWSER_SUPPORT.md`: browsers
- `ACTION.md`: change log

No credentials are reproduced in these documents. Default and demo logins are configured through `.env` and the seeders; see `.env.example`.
