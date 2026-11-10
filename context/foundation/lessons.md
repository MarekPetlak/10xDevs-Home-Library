# Lessons Learned

> Append-only register of recurring rules and patterns. Re-read at start by /10x-frame, /10x-research, /10x-plan, /10x-plan-review, /10x-implement, /10x-impl-review.

## Don't branch on framework message text

- **Context**: templates/security/login.html.twig:11 (S-01 review, F6)
- **Problem**: The template decides between the throttling message and the generic login error by checking whether the security error's message key starts with "Too many". A wording change in Symfony would silently show the generic message instead.
- **Rule**: Decide behaviour from exception classes or typed values, never from translated or framework message text.
- **Applies to**: Templates and controllers that react to framework errors.

## Prefer a service over a trait for shared code

- **Context**: src/Command/CreateUserCommand.php:53 and src/Command/ChangePasswordCommand.php (S-01 review, F9)
- **Problem**: Both account commands carried the same password-prompt code. Sharing it through a trait would hide the dependency, make it hard to test on its own, and couple the commands through mixed-in code.
- **Rule**: Treat traits as a last resort. Put shared behaviour in a small injectable service with its own test.
- **Applies to**: Commands, controllers and services that would otherwise share code through a trait.

## Pin both case and accent behaviour on uniquely indexed text columns

- **Context**: src/Entity/Author.php, src/Entity/Tag.php, migrations/Version20261103191639.php (S-02 review, F3)
- **Problem**: `utf8mb4_unicode_ci` was chosen only to get case-insensitive uniqueness for author and tag names. It is also accent-insensitive, so "José" and "Jose" collapsed into one author and the unique index rejected the second. Fixing it needed a later migration (`utf8mb4_uca1400_as_ci`).
- **Rule**: When picking a collation for a uniquely indexed text column, decide case and accent behaviour on purpose, pin the collation explicitly in the entity and the migration, and add a test with case and accent variants.
- **Applies to**: Doctrine entities with unique constraints on user-entered text.
