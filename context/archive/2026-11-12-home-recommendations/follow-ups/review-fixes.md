# Review follow-ups: home-recommendations

Queued from `reviews/impl-review.md`. Not part of this change; pick them up with the change named below.

## F4 — document how soft-deleted books count toward the top category (for S-08)

- **Pick up in**: S-08 `soft-delete-own-book`
- **Where**: `src/Repository/ReadBookRepository.php`, `countByCategorySince` (joins `r.book` for the category)
- **What**: Once S-08 adds soft delete, a deleted book would still count toward the user's most-read category, because the count goes through `r.book`. That is arguably right (the user did read it), but it must be a deliberate choice: state it in the method's docblock, or filter deleted books there if S-08 decides otherwise.
- **Also**: `BookRepository::findRecommendationCandidates` is the single place where S-08 must exclude deleted books from recommendations.
- **Decision (user, 2026-11-12)**: do nothing now; handle during S-08.
