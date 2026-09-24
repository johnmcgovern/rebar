<?php

/**
 * @file
 * Creates 100 tagged articles, each with 3 comments. Safe to rerun: it only
 * deletes content it created before (articles titled "Rust article N" and
 * their comments, tags named "Tag N"); other content is left alone.
 *
 * Run with: vendor/bin/drush php:script /work/bench/generate.php
 */

use Drupal\comment\Entity\Comment;
use Drupal\node\Entity\Node;
use Drupal\taxonomy\Entity\Term;

$etm = \Drupal::entityTypeManager();
$old_nids = \Drupal::entityQuery('node')->accessCheck(FALSE)->condition('title', 'Rust article ', 'STARTS_WITH')->execute();
if ($old_nids) {
  $comments = $etm->getStorage('comment')->loadByProperties(['entity_type' => 'node', 'entity_id' => $old_nids]);
  $etm->getStorage('comment')->delete($comments);
  $etm->getStorage('node')->delete($etm->getStorage('node')->loadMultiple($old_nids));
}
$old_tids = \Drupal::entityQuery('taxonomy_term')->accessCheck(FALSE)->condition('vid', 'tags')->condition('name', 'Tag ', 'STARTS_WITH')->execute();
$etm->getStorage('taxonomy_term')->delete($etm->getStorage('taxonomy_term')->loadMultiple($old_tids));

$tids = [];
foreach (range(1, 20) as $i) {
  $term = Term::create(['vid' => 'tags', 'name' => "Tag $i"]);
  $term->save();
  $tids[] = $term->id();
}

$body = str_repeat('<p>Lorem ipsum dolor sit amet, <strong>consectetur</strong> adipiscing elit. <a href="https://example.com">Link</a></p>', 20);
foreach (range(1, 100) as $i) {
  $node = Node::create([
    'type' => 'article',
    'title' => "Rust article $i",
    'body' => ['value' => $body, 'format' => 'basic_html'],
    'field_tags' => array_map(fn($tid) => ['target_id' => $tid], array_slice($tids, $i % 16, 4)),
    'promote' => 1,
    'uid' => 1,
  ]);
  $node->save();
  foreach (range(1, 3) as $c) {
    Comment::create([
      'entity_type' => 'node',
      'entity_id' => $node->id(),
      'field_name' => 'comment',
      'comment_type' => 'comment',
      'subject' => "Comment $c",
      'comment_body' => ['value' => "Comment $c on article $i", 'format' => 'basic_html'],
      'uid' => 1,
      'status' => 1,
    ])->save();
  }
}
// The 50th article and 10th tag: stable benchmark targets on every site.
$node = \Drupal::entityQuery('node')->accessCheck(FALSE)->condition('title', 'Rust article 50')->execute();
$term = \Drupal::entityQuery('taxonomy_term')->accessCheck(FALSE)->condition('vid', 'tags')->condition('name', 'Tag 10')->execute();
printf("nodes=%d comments=%d bench_paths=/node/%d /taxonomy/term/%d\n",
  $etm->getStorage('node')->getQuery()->accessCheck(FALSE)->count()->execute(),
  $etm->getStorage('comment')->getQuery()->accessCheck(FALSE)->count()->execute(),
  reset($node), reset($term));
