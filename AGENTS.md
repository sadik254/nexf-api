# NEXF backend project instructions

Read `../nexf-lifestyle/AGENTS.md` and `../nexf-lifestyle/docs/PROJECT_MEMORY.md` before coordinated frontend/backend work. If those files are unavailable, retain the following core rules:

- Backend work uses main. Frontend main is the design/interaction reference; integration/live-api-plus-main is the implementation branch; frontend production pushes trigger deployment.
- Reference-matching tasks require exhaustive page/workflow comparison, including every field, default, layout and interaction. Builds and API tests do not establish full UI parity.
- Implement required API persistence and preserve authorization/scoping. Missing contracts are unresolved requirements and must be documented, not silently omitted or replaced by mock data.
- Maintain a requirements/differences/verification checklist. User examples of defects do not replace the original requested scope.
- Report incomplete or unverified work explicitly. Before authorized promotion, identify the concrete release and remaining gaps; existing user authorization remains valid.
- Repository push, CI/CD success, backend deployment and live verification are separate statuses. Verify each before claiming success.
- Never store credentials in project memory or instructions.
