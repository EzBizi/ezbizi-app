<?php

namespace Tests\Feature\People;

use App\Models\User;
use App\Support\People\NameMatch;
use Tests\TestCase;

/** Desk 11-20: Workiz's "Booked by" spellings against the users here. */
class NameMatchTest extends TestCase
{
    public function test_the_same_person_spelled_differently_is_found_and_two_candidates_are_nobody(): void
    {
        $people = collect(['Hyanne Faelnar', 'Chevel Richards', 'Bryan Lagazon', 'Marydem Lucenara', 'Jennine Lizer', 'Sasha marie Solano',
            'Gino Russo-Folco', 'Rolando De Lara', 'Andy Ocampo Mejia', 'Marjulie Quino', 'Natalie Escobar', 'Nelly Escobar', 'Ana Ruiz', 'Ana Ruiz-Lopez'])
            ->map(fn ($n, $i) => (new User)->forceFill(['id' => 'u'.$i, 'name' => $n, 'is_active' => true]));
        $found = fn ($n) => NameMatch::find($n, $people)?->name;

        foreach ([
            'Hyanne Kylla Faelnar' => 'Hyanne Faelnar', 'Chevel Richards Turner' => 'Chevel Richards', 'Bryan Nixon Lagazon' => 'Bryan Lagazon',
            'Marydem S. Lucenara' => 'Marydem Lucenara', 'Jennine Lizar' => 'Jennine Lizer', 'Sasha Solano' => 'Sasha marie Solano',
            'Gino Russo Folco' => 'Gino Russo-Folco', 'Rolando De Lara Jr' => 'Rolando De Lara', 'Andy Ocampo' => 'Andy Ocampo Mejia',
            'Mar Quino' => 'Marjulie Quino', 'Nelly Escobar' => 'Nelly Escobar',
        ] as $workiz => $user) {
            $this->assertSame($user, $found($workiz), $workiz);
        }
        // The controls: nobody like it, a first name alone, and two possible people.
        $this->assertNull($found('Ken McLeod'));
        $this->assertNull($found('Rachelle'));
        $this->assertNull($found('Ana Ruiz Lopez'), 'two candidates is nobody');
    }
}
