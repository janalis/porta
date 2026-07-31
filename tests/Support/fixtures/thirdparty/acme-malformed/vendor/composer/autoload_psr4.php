<?php

// A map with every shape composer never emits, so the reader's refusals are
// exercised: a numeric key, a non-string directory, and a bare string where
// composer always writes a list.

return array(
    0 => 'not-a-prefix',
    'Bad\\Dirs\\' => array(1234, false),
    'Bare\\String\\' => 'one-directory-not-a-list',
);
