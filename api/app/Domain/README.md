# app/Domain — bounded contexts

Each directory is a bounded context (see `../../../docs/ARCHITECTURE_PROPOSAL.md` §2).

```
<Context>/
├── Models/      Eloquent models owned by this context
├── Actions/     one class, one public method, one job; owns the transaction
├── Data/        DTOs crossing layer boundaries
├── Events/      past-tense domain events this context emits
├── Listeners/   this context's reactions to other contexts' events
├── Policies/    the only place authorization decisions live
├── Enums/       every status; no magic strings
├── Exceptions/  extend App\Support\Exceptions\DomainException
└── Queries/     purpose-built read models (CurriculumTreeQuery, CourseCardQuery)
```

## The dependency rule

A context may **read** another context's Query/Resource, and may **listen** to its
events. It may not write another context's tables, call another context's Actions
directly, or import another context's Eloquent model into its own Action.

Cross-context writes go through events. `Progress` does not call `Gamification`;
it fires `ItemCompleted` and Gamification listens.

Directories are empty until their phase lands — see `docs/ROADMAP.md`.
