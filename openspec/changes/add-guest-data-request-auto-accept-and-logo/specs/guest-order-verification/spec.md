# Spec Delta

## Purpose

Lets a visitor who is not logged in prove that they control the e-mail address of an order, by receiving and entering a single-use code, so that the module can offer order-bound actions (today: the billing-data change request) without a customer login and without revealing anything about the order before the proof.

## ADDED Requirements

### Requirement: Guest request page
The module SHALL expose a front page for visitors who are not logged in, reachable without a token, that asks for an order reference and an e-mail address. A logged-in customer opening it SHALL be redirected to their order history. The page SHALL be served over HTTPS when the shop uses it and SHALL be available only while the data-change feature switch is on; otherwise it SHALL state that changes are not available.

#### Scenario: Guest opens the page
- **WHEN** a visitor without a customer session opens the guest request page
- **THEN** a form with the order reference and e-mail fields is shown and no order data is displayed

#### Scenario: Logged-in customer
- **WHEN** a logged-in customer opens the guest request page
- **THEN** they are redirected to their order history, which links to the account form of each order

#### Scenario: Feature disabled
- **WHEN** the data-change feature switch is off and a visitor opens the page or posts to it
- **THEN** the page states that changes are not available and no code is created or sent

### Requirement: Identification answer does not reveal the order
On a posted reference and e-mail, the module SHALL look for an order with that reference whose customer e-mail equals the posted e-mail ignoring case. Whether or not such an order exists, is eligible, or a code was actually sent, the page SHALL show the same message saying that a code was sent if the data matches an order, and SHALL take a comparable time to answer.

#### Scenario: Matching order
- **WHEN** the reference `ABCDEFGHI` and the e-mail of that order's customer are posted
- **THEN** a code is e-mailed to that address and the page shows the neutral "code sent if the data matches" message

#### Scenario: Unknown reference
- **WHEN** a reference that belongs to no order is posted
- **THEN** no e-mail is sent and the page shows the same neutral message

#### Scenario: E-mail of another customer
- **WHEN** the reference of an existing order is posted with an e-mail that differs from the order customer's e-mail
- **THEN** no e-mail is sent and the page shows the same neutral message

#### Scenario: Order already documented
- **WHEN** the posted reference and e-mail match an order that already has an issued or adopted document
- **THEN** no code is created, no e-mail is sent and the page shows the same neutral message

### Requirement: Single-use code
A code SHALL be six decimal digits generated from the operating system's secure random source, SHALL be valid for 15 minutes, SHALL be usable once, and SHALL be stored only as a salted hash with the order, the creation time, the expiry and the attempt count. Issuing a new code for an order SHALL invalidate every earlier unused code of that order.

#### Scenario: Code format and lifetime
- **WHEN** a code is issued at 10:00
- **THEN** the mailed code has six digits and is refused when entered at 10:16

#### Scenario: Reuse refused
- **WHEN** a code was already accepted once and is entered again
- **THEN** it is refused as invalid

#### Scenario: New code replaces the old
- **WHEN** a second code is issued for the same order and the first one is entered
- **THEN** the first code is refused and only the second one is accepted

#### Scenario: Stored form
- **WHEN** a code is stored
- **THEN** the plain code appears nowhere in the database or the logs

### Requirement: Code e-mail
The code SHALL be sent to the order customer's e-mail, in the order's language when the module ships the template for it (English otherwise), from the shop's sender, with the order reference, the code, its validity in minutes and a sentence telling the recipient to ignore the message when they did not ask for it. The e-mail SHALL NOT contain a link that verifies the code by itself.

#### Scenario: Polish order
- **WHEN** a code is issued for an order placed in Polish
- **THEN** the customer receives a Polish e-mail that contains the order reference, the six-digit code and the 15-minute validity

#### Scenario: Unsupported language
- **WHEN** the order's language has no module template
- **THEN** the English template is used

#### Scenario: No self-verifying link
- **WHEN** the e-mail is inspected
- **THEN** no link in it carries the code

### Requirement: Code verification and attempts
The page SHALL accept the code for the order identified in the current step and SHALL compare it in constant time. After 5 wrong attempts the code SHALL be invalidated and a new one must be requested. A wrong, expired or invalidated code SHALL be answered with one generic "invalid or expired code" message.

#### Scenario: Correct code
- **WHEN** the visitor enters the mailed code within its lifetime
- **THEN** the verification succeeds and the order-bound form is shown

#### Scenario: Wrong code
- **WHEN** the visitor enters a code that differs from the mailed one
- **THEN** the page answers "invalid or expired code" and the attempt is counted

#### Scenario: Attempts exhausted
- **WHEN** the fifth wrong attempt is made and then the correct code is entered
- **THEN** the correct code is refused and the page offers to request a new code

#### Scenario: Expired code
- **WHEN** the code is entered after its lifetime
- **THEN** the page answers the same "invalid or expired code" message

### Requirement: Issuance limits
The module SHALL refuse to issue a new code for an order within 60 seconds of the previous one, more than 5 codes for the same order within 24 hours, and more than 20 codes for the same client address within one hour. A refused issuance SHALL send no e-mail and SHALL show the neutral "code sent if the data matches" message.

#### Scenario: Immediate resend
- **WHEN** a code was issued 30 seconds ago and the same reference and e-mail are posted again
- **THEN** no new e-mail is sent and the neutral message is shown

#### Scenario: Per-order cap
- **WHEN** five codes were issued for an order in the last 24 hours and a sixth is requested
- **THEN** no e-mail is sent and the neutral message is shown

#### Scenario: Per-address cap
- **WHEN** a client address requested 20 codes in the last hour for different orders and requests another
- **THEN** no e-mail is sent and the neutral message is shown

#### Scenario: Window over
- **WHEN** the per-order 60-second window is over and a code is requested again
- **THEN** a new code is issued and e-mailed

### Requirement: Verified guest session
A successful verification SHALL open a verified session bound to that one order, kept in the visitor's shop cookie, valid for 30 minutes, and SHALL be ended by a successful submission or cancellation, by its expiry, or by logging in. A request to an order-bound action without a verified session for that order SHALL be refused and the page SHALL return to the identification step.

#### Scenario: Session reaches the form
- **WHEN** the code was verified for order 42 and the visitor opens the form within 30 minutes
- **THEN** the form for order 42 is shown without a new code

#### Scenario: Session for another order
- **WHEN** the session is verified for order 42 and the visitor posts the form with order 43
- **THEN** the action is refused and the identification step is shown

#### Scenario: Session expired
- **WHEN** the visitor opens the form 31 minutes after verification
- **THEN** the identification step is shown

#### Scenario: Session consumed
- **WHEN** the visitor submits a valid request
- **THEN** the session ends and the confirmation page offers no further order-bound action without a new code

### Requirement: CSRF protection of the guest steps
Every posted step (identify, verify, submit, cancel) SHALL carry the per-cookie form token and SHALL be refused without it.

#### Scenario: Missing token on identification
- **WHEN** the identification form is posted without the token
- **THEN** no code is issued and the page shows the token error

#### Scenario: Wrong token on verification
- **WHEN** the code is posted with a token that does not match the cookie
- **THEN** the code is not checked, no attempt is counted and the token error is shown

### Requirement: Housekeeping of codes
Expired and used codes older than 24 hours SHALL be deleted whenever a new code is issued, so the table never grows beyond the recent activity.

#### Scenario: Old rows removed
- **WHEN** a code is issued and the table holds a used code from two days ago
- **THEN** that old row is deleted

### Requirement: Guest page needs the shop's cookie key
The guest page SHALL state that changes are not available, and SHALL create and verify no code, while the shop's cookie key (`_COOKIE_KEY_`) is empty or undefined, because the session signature, the form token and the code hashes are keyed with it.

#### Scenario: Shop without a cookie key
- **WHEN** the shop's cookie key is empty and a visitor opens the page or posts any step to it
- **THEN** the page states that changes are not available, no code is issued or verified, no mail is sent and no session is opened

### Requirement: Minimum answer time of the identification
The identification SHALL take at least 1.5 seconds, counted from the start of the request handling, on every branch, so the answer time does not tell whether the reference and the e-mail matched an order. An answer that is slower than that anyway SHALL NOT be delayed further.

#### Scenario: Fast answer is padded
- **WHEN** the identification is posted and the work took 0.2 seconds, whether the data matched an order, matched none, belonged to another customer or was refused by a limit
- **THEN** the page waits for the remaining 1.3 seconds before it answers

#### Scenario: Slow answer is not delayed
- **WHEN** the identification already took 1.6 seconds
- **THEN** the page does not wait any longer

### Requirement: Keyed code hash
The stored hash of a code SHALL be an HMAC-SHA-256 of the salt and the code keyed with the shop's cookie key, so a copy of the table alone does not allow the six digits to be brute-forced.

#### Scenario: Hash depends on the cookie key
- **WHEN** a code is issued under one cookie key and entered under another
- **THEN** it is refused, and the stored hash differs from the unkeyed hash of the salt and the code

### Requirement: Code e-mail subject without the reference
The subject of the code e-mail SHALL name the shop and SHALL NOT contain the order reference, which stays in the body.

#### Scenario: Subject without the reference
- **WHEN** a code is mailed for order `ABCDEFGHI` by the shop "Test Shop"
- **THEN** the subject is "Your verification code from Test Shop" and the reference appears in the body only

### Requirement: Atomic attempt counting
Each verification attempt SHALL be counted by one conditional database update (code unused, valid, not expired, fewer than 5 attempts) before the code is compared, and only the request whose update counted the attempt SHALL compare, so parallel guesses cannot exceed 5 comparisons per code.

#### Scenario: Parallel guess after the last attempt
- **WHEN** a guess read the code before another request took its fifth attempt, so its own attempt update changes no row
- **THEN** the guess is refused without being compared, even when it is the right code, and nothing is written

### Requirement: Serialised issuance
The counts of the issuance limits and the insert of a code SHALL run under one database lock per order, released on every exit; a request that cannot get the lock within 2 seconds SHALL be refused like a limit.

#### Scenario: Parallel identifications
- **WHEN** several identifications for the same order arrive at the same moment
- **THEN** they are served one after the other under the order lock, the resend window refuses all but the first, and only one code is mailed

#### Scenario: Lock not granted
- **WHEN** the order lock is not granted within 2 seconds
- **THEN** no count is read, no code is stored, no e-mail is sent and the page shows the neutral message

### Requirement: Form token per visitor and POST only
The form token of every posted step SHALL be derived from a random value kept in the visitor's own cookie and the shop's cookie key (HMAC-SHA-256), a token handed to another visitor SHALL be refused, and a step SHALL change state only when the request method is POST.

#### Scenario: Token of another visitor
- **WHEN** a step is posted with the token that the page handed to a different visitor
- **THEN** the step is refused with the token error and nothing changes

#### Scenario: State change over GET
- **WHEN** a request by GET carries a valid token and every field of a step, for example in a link or an image address
- **THEN** no code is issued or verified, no request is stored or cancelled, no mail is sent and the page is only rendered

### Requirement: Neutral failure of a submission
An unexpected error while a verified guest's request is stored or cancelled SHALL be answered with a neutral message and logged by the class of the error only.

#### Scenario: Unexpected error
- **WHEN** storing a verified guest's request raises an unexpected error
- **THEN** the form is shown again with "The request could not be saved. Please try again.", the session is kept, and the log names the class of the error without its text
