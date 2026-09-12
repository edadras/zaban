<?php

use App\Models\VocabularySense;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Words the scanner turned into other words, read again off the page.
 *
 * The failed pass confuses a small, consistent set of letters - o with a, s
 * with c, p with n, b with h, y with u - so "important" reached the catalogue
 * as "imnartant" and "phrase" as "nhraca". Thirty-seven headwords were being
 * taught in that state: a flashcard showing "ctill" and asking the learner to
 * recall what it means.
 *
 * Every repair here is the book's own word, not a decoding of the cipher. The
 * page each one came from was rendered again at 400 dpi and read again, and the
 * word the second read returned is what is written below. Where the second read
 * could not recover the word, nothing is guessed: the entry is set aside, which
 * stops it being taught and leaves the row for review.
 *
 * Two of the set-aside rows are a person in one of the book's examples rather
 * than a misread - "Mariam", "DONATA". They are real on the page and still not
 * something to put on a flashcard.
 *
 * Four definitions are set aside whole. They are not damaged words but a column
 * of the page read twice and interleaved - "said Penid when ashan about ahaut
 * give aie voir be" - and there is no line in them to recover. The senses keep
 * their hand-authored Persian meaning, which is what the card now shows.
 */
return new class extends Migration
{
    /** vocabulary item id => [what the scanner made of it, what the page says] */
    private const REREAD = [
        3705 => ['articla', 'article'],
        3706 => ['nravidac', 'provides'],
        3943 => ['imnartant', 'important'],
        3945 => ['nart', 'part'],
        3947 => ['manu', 'many'],
        4216 => ['nian', 'plan'],
        4280 => ['haat!', 'heat'],
        4373 => ['Thaca', 'these'],
        4374 => ['cantain', 'contain'],
        4375 => ['tunical', 'typical'],
        4470 => ['streng', 'strong'],
        4606 => ['Thara', 'There'],
        4607 => ['climata', 'climate'],
        4697 => ['ctill', 'still'],
        4868 => ['nhraca', 'phrase'],
        5057 => ['cama', 'Some'],
        5313 => ['Natica', 'Notice'],
        5315 => ['fram', 'from'],
        6751 => ['aftan', 'often'],
        6780 => ['heln', 'help'],
        6788 => ['narcan', 'person'],
        6872 => ['ranaat', 'repeat'],
        7120 => ['ralac', 'roles'],
        7200 => ['namac', 'names'],
        7559 => ['whan', 'when'],
        7563 => ['anlv', 'only'],
        7615 => ['Hore', 'Here'],
        7621 => ['hattar', 'better'],
        7622 => ['ctata', 'state'],
        7635 => ['dafinitoly', 'definitely'],
        7817 => ['annacite', 'opposite'],
        7842 => ['aftar', 'after'],
        7985 => ['easilv', 'easily'],
        7986 => ['Thev', 'They'],
        7987 => ['idiam', 'idiom'],
        7989 => ['verv', 'very'],
        9104 => ['nood', 'meet'],
    ];

    /** vocabulary item id => [the damaged headword, why it is not taught] */
    private const SET_ASIDE = [
        3749 => ['tavt', 'the scanner misread it and a second read could not recover it'],
        3946 => ['ranarte', 'the scanner misread it and a second read could not recover it'],
        4766 => ['avame', 'the scanner misread it and a second read could not recover it'],
        5068 => ['nanr', 'the scanner misread it and a second read could not recover it'],
        6791 => ['caame', 'the scanner misread it and a second read could not recover it'],
        6815 => ['emall', 'the scanner misread it and a second read could not recover it'],
        6871 => ['carand', 'the scanner misread it and a second read could not recover it'],
        7197 => ['mala', 'the scanner misread it and a second read could not recover it'],
        7198 => ['imnart', 'the scanner misread it and a second read could not recover it'],
        7199 => ['avamnala', 'the scanner misread it and a second read could not recover it'],
        7260 => ['idiame', 'the scanner misread it and a second read could not recover it'],
        7261 => ['Dand', 'the scanner misread it and a second read could not recover it'],
        7379 => ['maane', 'the scanner misread it and a second read could not recover it'],
        7395 => ['alen', 'the scanner misread it and a second read could not recover it'],
        7640 => ['narean', 'the scanner misread it and a second read could not recover it'],
        7814 => ['nlane', 'the scanner misread it and a second read could not recover it'],
        8520 => ['Mariam', 'a person in the book’s example, not a word it teaches'],
        9198 => ['stipped', 'the scanner misread it and a second read could not recover it'],
        9259 => ['calll', 'the scanner misread it and a second read could not recover it'],
        9269 => ['Laan', 'the scanner misread it and a second read could not recover it'],
        9380 => ['DONATA', 'a person in the book’s example, not a word it teaches'],
    ];

    /** definition id => the opening of the scrambled text, to confirm before deleting */
    private const SCRAMBLED = [
        1583 => 'said Penid when ashan about ahaut give aie voir be if abt eb help ada him. btn In Bee to ta your a wee) You ask | think ',
        1648 => 'beginning opposite annacite government anuarnmant to tn over avar say cav backwards',
        1649 => 'beginning opposite annacite government anuarnmant to tn over avar say cav backwards',
        1653 => 'beginning opposite annacite government anuarnmant to tn over avar say cav backwards',
    ];

    public function up(): void
    {
        $this->reread(1);

        foreach (self::SET_ASIDE as $itemId => $pair) {
            $this->teach($itemId, false, $pair[1]);
        }

        foreach (self::SCRAMBLED as $id => $opening) {
            DB::table('definitions')
                ->where('id', $id)
                ->where('text', 'like', substr($opening, 0, 60).'%')
                ->delete();
        }
    }

    public function down(): void
    {
        $this->reread(0);

        foreach (self::SET_ASIDE as $itemId => $pair) {
            $this->teach($itemId, true, null);
        }

        // The scrambled definitions are gone for good: they carried no meaning
        // to restore, and the sense reads better without them.
    }

    /** Put the headword, and the concept that shows it, one way round or the other. */
    private function reread(int $to): void
    {
        foreach (self::REREAD as $itemId => $pair) {
            $from = $pair[1 - $to];
            $into = $pair[$to];

            DB::table('vocabulary_items')
                ->where('id', $itemId)
                ->where('headword', $from)
                ->update(['headword' => $into, 'normalised' => mb_strtolower($into), 'updated_at' => now()]);

            $senses = DB::table('vocabulary_senses')->where('vocabulary_item_id', $itemId)->pluck('id');

            DB::table('concepts')
                ->where('conceptable_type', VocabularySense::class)
                ->whereIn('conceptable_id', $senses)
                ->where('label', $from)
                ->update(['label' => $into, 'updated_at' => now()]);
        }
    }

    /**
     * Whether this item's concepts are offered to learners at all.
     *
     * The reason is written down beside the switch. The build recomputes
     * is_active from scratch on every pass, and only a recorded reason survives
     * it - without one the flashcard for "nhraca" comes straight back.
     */
    private function teach(int $itemId, bool $active, ?string $reason): void
    {
        $senses = DB::table('vocabulary_senses')->where('vocabulary_item_id', $itemId)->pluck('id');

        DB::table('concepts')
            ->where('conceptable_type', VocabularySense::class)
            ->whereIn('conceptable_id', $senses)
            ->update(['is_active' => $active, 'retired_reason' => $reason, 'updated_at' => now()]);
    }
};
