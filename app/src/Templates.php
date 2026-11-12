<?php // app/src/Templates.php
namespace App;

use Kip\Database;

/** Editable mail templates. Every mail the app sends resolves its subject and
 *  body here: a saved row overrides, a missing row falls through to the
 *  literal pair the call site passes, so an unseeded (or unedited) database
 *  sends byte-identical mail to the pre-template code. The literal defaults
 *  below carry the same {placeholders} the call sites feed to strtr, which is
 *  what keeps the fallthrough byte-identical after seeding too.
 *
 *  Distinct names (one per mail): the queue's approve/publish path and the chapter
 *  controller's publish path send the SAME story_update mail (finding 15). */
final class Templates
{
    /** Placeholder vocabulary per template: every key its call sites feed to
     *  strtr, so the admin editor can document exactly what may appear. A
     *  placeholder missing from a saved body simply never renders. */
    public const VOCABULARY = [
        'member_verify' => ['{url}' => 'the account verification link'],
        'we_moved' => [],
        'password_reset' => ['{url}' => 'the password reset link'],
        'signin_link' => ['{url}' => 'the one-time sign-in link'],
        'site_contact' => [
            '{name}' => 'the sender\'s name', '{email}' => 'the sender\'s email address', '{message}' => 'the message text',
        ],
        'email_change_confirm' => ['{url}' => 'the link that confirms the new email address'],
        'email_changed_notice' => ['{new_email}' => 'the new email address'],
        'coauthor_added' => [
            '{title}' => 'the story title',
            '{slug}' => 'the story slug',
            '{url}' => 'the story management link',
        ],
        'member_contact' => [
            '{message}' => 'the message the member wrote',
            '{penname}' => 'the sender penname',
            '{sender_slug}' => 'the sender profile slug',
            '{url}' => 'the reply link back to the sender',
        ],
        'story_update' => [
            '{title}' => 'the story title',
            '{slug}' => 'the story slug',
            '{pos}' => 'the new chapter position',
            '{url}' => 'the read link for the new chapter',
        ],
        'digest' => [
            '{since}' => 'the digest window start',
            '{lines}' => 'the notification lines',
        ],
        'story_accepted' => [
            '{title}' => 'the story title',
            '{url}' => 'the link to the live story',
        ],
        'story_rejected' => [
            '{title}' => 'the story title',
            '{reason}' => 'the moderator reason, or a polite default',
        ],
        'member_rejected' => [
            '{penname}' => 'the applicant pen name',
            '{reason}' => 'the moderator reason, or a polite default',
        ],
        'content_removed' => [
            '{what}' => 'what was removed (story or review)',
            '{title}' => 'the story title',
            '{reason}' => 'the moderator reason, or a polite default',
        ],
    ];

    /** The content_removed pair, shared by the call site and seedDefaults. */
    public const DEFAULT_CONTENT_REMOVED = ['Your {what} was removed',
        "A moderator removed your {what} on \"{title}\".\n\n{reason}\n\nIf you think this was a mistake, reply to this email or contact the archive's owner."];

    /** Today's literals. These must stay byte-identical to the defaults the
     *  call sites pass: the pair a member receives before any admin edit is
     *  the pair the pre-template code interpolated. */
    private const DEFAULTS = [
        'member_verify' => ['Verify your account',
            "Welcome to the archive. Confirm your address:\n\n{url}\n\nThe link is valid for 24 hours."],
        'we_moved' => ['The archive moved',
            "The archive you were a member of has moved.\n\nGood news: the password you just used still works, "
            . "and it is now stored with modern hashing. No action needed; this is just a heads up."],
        'password_reset' => ['Reset your password',
            "Someone (hopefully you) asked to reset the password for this address.\n\n"
            . "Reset link (valid 30 minutes):\n{url}\n\nIf this wasn't you, ignore this email."],
        'signin_link' => ['Your sign-in link',
            "Here is the sign-in link you asked for:\n\n{url}\n\n"
            . "It works once, for the next 30 minutes. Open it on the device you want to sign in on.\n\n"
            . "If you didn't ask for this, you can ignore this email."],
        'site_contact' => ['New message from the contact form',
            "{name} ({email}) wrote through the contact form:\n\n{message}\n\n"
            . "Reply to this person at {email}. They are not signed in."],
        'email_change_confirm' => ['Confirm your new email address',
            "Someone asked to use this address for an account on the archive. To confirm it is yours, open this link:\n\n{url}\n\n"
            . "The link works once and expires in 24 hours. If this wasn't you, ignore this email and nothing will change."],
        'email_changed_notice' => ['Your email address was changed',
            "The email address on your archive account was changed to {new_email}.\n\n"
            . "If you did this, there is nothing more to do. If you didn't, contact the archive owner right away."],
        'coauthor_added' => ['You were added as a coauthor on {title}',
            "You were added as a coauthor on \"{title}\".\n\nView and manage the story:\n{url}"],
        'member_contact' => ['Message from {penname}',
            "{message}\n\nReply via {url}"],
        'story_update' => ['Story update: {title}',
            "A story you follow has a new chapter:\n\n{title}\n{url}"],
        'digest' => ['Your archive digest',
            "Since {since}:\n\n{lines}\n--\nYou receive this because at least one follow is in digest mode."],
        'story_accepted' => ['Your story {title} was approved',
            "Good news. \"{title}\" was approved and is now live in the archive.\n\n{url}"],
        'story_rejected' => ['Your story {title} was not approved',
            "Thanks for submitting \"{title}\". A moderator decided not to publish it.\n\n{reason}\n\nYou are welcome to reply to this email with questions."],
        'member_rejected' => ['About your membership request',
            "Hello {penname},\n\nThanks for asking to join. A moderator decided not to approve the account.\n\n{reason}\n\nYou are welcome to reply to this email with questions."],
        'content_removed' => self::DEFAULT_CONTENT_REMOVED,
    ];

    /** The built-in [subject, body] for a known name, for call sites that
     *  pass them to get() as the fallthrough. */
    public static function pair(string $name): array
    {
        return self::DEFAULTS[$name];
    }

    /** @return array{0: string, 1: string} the [subject, body] pair: the saved
     *  row when an admin edited the name, the passed literals otherwise. No
     *  caching: mail sends are POST/CLI surfaces, never page renders. */
    public static function get(Database $db, string $name, string $defaultSubject, string $defaultBody): array
    {
        $row = $db->one('SELECT subject, body FROM mail_templates WHERE name = ?', [$name]);
        if ($row === null) return [$defaultSubject, $defaultBody];
        return [(string) $row['subject'], (string) $row['body']];
    }

    /** INSERT OR IGNORE every known name (name is the PK), so the admin
     *  edit surface always has rows to edit; existing overrides survive.
     *  Idempotent by construction. */
    public static function seedDefaults(Database $db): void
    {
        foreach (self::DEFAULTS as $name => $pair) {
            $db->query('INSERT OR IGNORE INTO mail_templates (name, subject, body) VALUES (?, ?, ?)',
                [$name, $pair[0], $pair[1]]);
        }
    }
}
