import 'package:flutter_test/flutter_test.dart';

import 'package:fkss_app/services/taxonomy_names.dart';

/// P35: duplicate-name prevention for categories, sub-categories and
/// singers.
void main() {
  List<Map<String, dynamic>> cats() => [
        {'id': 1, 'name': 'Test', 'parent_id': 0},
        {'id': 2, 'name': 'Praise', 'parent_id': 0},
        {'id': 3, 'name': 'subb', 'parent_id': 1},
        {'id': 4, 'name': 'Morning', 'parent_id': 2},
      ];

  int idOf(Map<String, dynamic> r) => r['id'] as int;
  String nameOf(Map<String, dynamic> r) => '${r['name']}';
  int? parentOf(Map<String, dynamic> r) => r['parent_id'] as int?;

  group('normalize', () {
    test('lowercases and trims', () {
      expect(TaxonomyNames.normalize('  Test '), 'test');
    });

    test('collapses internal whitespace runs', () {
      expect(TaxonomyNames.normalize('te   st'), 'te st');
      expect(TaxonomyNames.normalize('a\t\tb'), 'a b');
    });

    test('handles non-breaking spaces', () {
      expect(TaxonomyNames.normalize('te\u00A0st'), 'te st');
    });

    test('keeps punctuation and Ethiopic text intact', () {
      expect(TaxonomyNames.normalize(' መዝሙር '), 'መዝሙር');
      expect(TaxonomyNames.normalize("St. Mary's"), "st. mary's");
    });

    test('an empty or blank name normalises to empty', () {
      expect(TaxonomyNames.normalize('   '), '');
    });
  });

  group('sameName', () {
    test('case and spacing differences are the same name', () {
      expect(TaxonomyNames.sameName('Test', ' test '), isTrue);
      expect(TaxonomyNames.sameName('te st', 'te  st'), isTrue);
    });

    test('genuinely different names are not equal', () {
      expect(TaxonomyNames.sameName('Test', 'Tests'), isFalse);
    });
  });

  group('category duplicates — the reported bug', () {
    test('exact repeat is rejected', () {
      expect(
        TaxonomyNames.isDuplicate(
          name: 'Test',
          rows: cats(),
          idOf: idOf,
          nameOf: nameOf,
          parentId: null,
          parentOf: parentOf,
        ),
        isTrue,
      );
    });

    test('different case and trailing space is still a duplicate', () {
      expect(
        TaxonomyNames.isDuplicate(
          name: '  tESt ',
          rows: cats(),
          idOf: idOf,
          nameOf: nameOf,
          parentId: null,
          parentOf: parentOf,
        ),
        isTrue,
      );
    });

    test('a free name is allowed', () {
      expect(
        TaxonomyNames.isDuplicate(
          name: 'Evening',
          rows: cats(),
          idOf: idOf,
          nameOf: nameOf,
          parentId: null,
          parentOf: parentOf,
        ),
        isFalse,
      );
    });

    test('the colliding row is returned so it can be named', () {
      final hit = TaxonomyNames.findDuplicate(
        name: 'test',
        rows: cats(),
        idOf: idOf,
        nameOf: nameOf,
        parentId: null,
        parentOf: parentOf,
      );
      expect(hit?['name'], 'Test');
    });
  });

  group('sub-category scoping', () {
    test('duplicate under the SAME parent is rejected', () {
      expect(
        TaxonomyNames.isDuplicate(
          name: 'subb',
          rows: cats(),
          idOf: idOf,
          nameOf: nameOf,
          parentId: 1,
          parentOf: parentOf,
        ),
        isTrue,
      );
    });

    test('the same sub name under a DIFFERENT parent is allowed', () {
      expect(
        TaxonomyNames.isDuplicate(
          name: 'subb',
          rows: cats(),
          idOf: idOf,
          nameOf: nameOf,
          parentId: 2,
          parentOf: parentOf,
        ),
        isFalse,
      );
    });

    test('a main name does not collide with a sub of the same name', () {
      // 'Morning' exists under parent 2; creating a MAIN 'Morning' is fine.
      expect(
        TaxonomyNames.isDuplicate(
          name: 'Morning',
          rows: cats(),
          idOf: idOf,
          nameOf: nameOf,
          parentId: null,
          parentOf: parentOf,
        ),
        isFalse,
      );
    });
  });

  group('editing an existing row', () {
    test('a row never collides with itself', () {
      expect(
        TaxonomyNames.isDuplicate(
          name: 'Test',
          rows: cats(),
          idOf: idOf,
          nameOf: nameOf,
          selfId: 1,
          parentId: null,
          parentOf: parentOf,
        ),
        isFalse,
      );
    });

    test('changing only the case of your own name is allowed', () {
      expect(
        TaxonomyNames.isDuplicate(
          name: 'TEST',
          rows: cats(),
          idOf: idOf,
          nameOf: nameOf,
          selfId: 1,
          parentId: null,
          parentOf: parentOf,
        ),
        isFalse,
      );
    });

    test('renaming onto ANOTHER row is still rejected', () {
      expect(
        TaxonomyNames.isDuplicate(
          name: 'Praise',
          rows: cats(),
          idOf: idOf,
          nameOf: nameOf,
          selfId: 1,
          parentId: null,
          parentOf: parentOf,
        ),
        isTrue,
      );
    });
  });

  group('singers — flat list, no parent scope', () {
    final singers = [
      {'id': 10, 'name': 'testets'},
      {'id': 11, 'name': 'ዘማሪ'},
    ];

    test('the duplicate from the screenshot is now caught', () {
      expect(
        TaxonomyNames.isDuplicate(
          name: 'testets',
          rows: singers,
          idOf: idOf,
          nameOf: nameOf,
        ),
        isTrue,
      );
    });

    test('spacing variants are caught too', () {
      expect(
        TaxonomyNames.isDuplicate(
          name: ' Testets  ',
          rows: singers,
          idOf: idOf,
          nameOf: nameOf,
        ),
        isTrue,
      );
    });

    test('Amharic duplicates are caught', () {
      expect(
        TaxonomyNames.isDuplicate(
          name: 'ዘማሪ',
          rows: singers,
          idOf: idOf,
          nameOf: nameOf,
        ),
        isTrue,
      );
    });

    test('a new singer is allowed', () {
      expect(
        TaxonomyNames.isDuplicate(
          name: 'Yohannes',
          rows: singers,
          idOf: idOf,
          nameOf: nameOf,
        ),
        isFalse,
      );
    });
  });

  group('edge cases', () {
    test('a blank name is never reported as a duplicate', () {
      // Emptiness is the name validator's job, not the dup detector's.
      expect(
        TaxonomyNames.isDuplicate(
          name: '   ',
          rows: cats(),
          idOf: idOf,
          nameOf: nameOf,
        ),
        isFalse,
      );
    });

    test('an empty table collides with nothing', () {
      expect(
        TaxonomyNames.isDuplicate(
          name: 'Anything',
          rows: const [],
          idOf: idOf,
          nameOf: nameOf,
        ),
        isFalse,
      );
    });

    test('null parent and 0 parent both mean top level', () {
      final rows = [
        {'id': 1, 'name': 'Top', 'parent_id': null},
      ];
      expect(
        TaxonomyNames.isDuplicate(
          name: 'Top',
          rows: rows,
          idOf: idOf,
          nameOf: nameOf,
          parentId: 0,
          parentOf: (r) => r['parent_id'] as int?,
        ),
        isTrue,
      );
    });
  });
}
