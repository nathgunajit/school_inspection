# Product Details: School Inspection Management System

This folder holds every product and technical document of the project. The whole set is also combined in **one Word file: `School_Inspection_Product_Details.docx`**.

The technical chapters (Part A) were prepared on 19-11-2026, as asked in `Requirements/Documentation.docx`. They come from a read-only review of the source code, the migrations, the live database schema (structure only, no row data) and the UI.

## Part A: Technical documentation
| # | Document | For | Contents |
|---|---|---|---|
| A1 | [Database_Architecture_and_Schema.md](Database_Architecture_and_Schema.md) | Developers, DBA | 38 tables, every column, keys, relations, ER diagram, business logic |
| A2 | [Project_File_Structure_and_Technical_Architecture.md](Project_File_Structure_and_Technical_Architecture.md) | Developers | Stack, folders, file inventory, backend/frontend architecture, routes, commands |
| A3 | [School_Inspection_Mechanism.md](School_Inspection_Mechanism.md) | Everyone | Users, schools, planning, the full questionnaire, workflow, rating, reports |
| A4 | [User_Roles_and_Permissions.md](User_Roles_and_Permissions.md) | Admins, developers | Levels, permission matrix, approval chain, scoping, login, menu visibility |
| A5 | [UI_Pages_and_Application_Modules.md](UI_Pages_and_Application_Modules.md) | Developers, testers | Each page with route, controller, view and role; design system |
| A6 | [System_Architecture_and_Workflow.md](System_Architecture_and_Workflow.md) | Architects | 8 Mermaid diagrams: architecture, ER, access, hierarchy, workflows, data flow, modules |
| A7 | [Technical_Audit_and_Recommendations.md](Technical_Audit_and_Recommendations.md) | Management, developers | Security, DB, code and performance findings; inconsistencies; fixes |
| A8 | [Future_Recommendations_Gaps_and_Roadmap.md](Future_Recommendations_Gaps_and_Roadmap.md) | Management | Functional and technical gaps, technology choices, phased roadmap |
| A9 | [Installed_Packages_and_Libraries.md](Installed_Packages_and_Libraries.md) | Developers, operations | Every installed package and library, versions, licences, use case and where used; audit and update status |

## Part B: Project documents (kept up to date with each modification)
| # | Document | Contents |
|---|---|---|
| B1 | [PROJECT_DOCUMENT.md](PROJECT_DOCUMENT.md) | Functional document for users and developers, with the V6/V7 changes, teacher totals and working without network |
| B2 | [INSPECTION_FORM_DOCUMENT.md](INSPECTION_FORM_DOCUMENT.md) | The inspection form step by step, with screenshots |
| B3 | [NEW_MODULES_PLAN.md](NEW_MODULES_PLAN.md) | Plan and status of the New Modules and the V6 modules |
| B4 | [DB_INDEXING.md](DB_INDEXING.md) | Database index review |
| B5 | [LOAD_TEST.md](LOAD_TEST.md) | Load test method and capacity results |
| B6 | [BROWSER_SUPPORT.md](BROWSER_SUPPORT.md) | Supported browsers |

## Part C: Requirements (`Requirements/`)
These are copies of the source documents in `Documents/`. **`Documents/` stays the master copy**, because code comments cite it, for example "PRD 8.2".
- `School_Inspection_PRD.docx` (PRD v3.0), plus the earlier `PRODUCT REQUIREMENTS DOCUMENT.docx` and `Document.docx`
- `Modification of the ApplicationV1.docx` … `V7.docx`
- `Modification of the Application New Module.docx`
- `Documentation.docx`: the brief for Part A
- The paper form images (`*.png`)

The combined Word file lists them in an appendix. Their own content is not merged into it; open the files themselves for the full text and images.

## Notes
- **Status labels:** **Implemented** means it works in the current code. **Partial** means it is present but limited, or depends on configuration. **Not implemented** means it is absent (often planned for Phase 2).
- **Reading order:**
  - New developers: A2 → A1 → A3 → A4 → A5 → B1
  - Management: A3 → A7 → A8
  - Testers: B2 → A3 → A5 → A4
- **At the repository root:** `README.md`, `CLAUDE.md` (developer rules) and `ACTION.md` (change log).
- **No credentials** are reproduced here. Default and demo logins are configured through `.env` and the seeders; see `.env.example`.
