// P49 — targeted static check for the bug class that keeps breaking the
// build: calling a member on a NULLABLE FIELD without `?.`, `!` or a
// `?? default` guard.
//
// WHY THIS EXISTS
//
// The sandbox used to develop this app cannot host the full Flutter SDK,
// so `flutter analyze` cannot run on files that import `package:flutter`.
// A parse-only check (the previous safety net) proves syntax is well
// formed but resolves no types, so it happily accepted:
//
//     String? _staticLyrics;
//     ...
//     (_staticLyrics).trim()        // <- compile error, shipped anyway
//
// Dart does NOT type-promote non-final instance fields (a concurrent
// write could invalidate the promotion), so ANY unguarded member access
// on a nullable, non-final field is a guaranteed compile error. That
// makes this check precise rather than heuristic: it needs no type
// inference, only the AST, and it produces no false positives for the
// rule it enforces.
//
// USAGE
//   dart pub get                       # in tool/ (analyzer only)
//   dart run tool/nullable_field_lint.dart lib
//
// Exits non-zero when a violation is found, so CI can gate on it.

import 'dart:io';

import 'package:analyzer/dart/analysis/features.dart';
import 'package:analyzer/dart/analysis/utilities.dart';
import 'package:analyzer/dart/ast/ast.dart';
import 'package:analyzer/dart/ast/visitor.dart';

void main(List<String> args) {
  final roots = args.isEmpty ? <String>['lib'] : args;
  final files = <File>[];
  for (final r in roots) {
    final t = FileSystemEntity.typeSync(r);
    if (t == FileSystemEntityType.directory) {
      files.addAll(Directory(r)
          .listSync(recursive: true)
          .whereType<File>()
          .where((f) => f.path.endsWith('.dart')));
    } else if (t == FileSystemEntityType.file) {
      files.add(File(r));
    }
  }

  var violations = 0;
  for (final f in files) {
    final result = parseFile(
      path: f.absolute.path,
      featureSet: FeatureSet.latestLanguageVersion(),
    );
    if (result.errors.isNotEmpty) {
      for (final e in result.errors) {
        stderr.writeln('${f.path}: SYNTAX ${e.message}');
      }
      violations += result.errors.length;
      continue;
    }
    final visitor = _NullableFieldVisitor(f.path, result.lineInfo);
    result.unit.visitChildren(visitor);
    for (final v in visitor.problems) {
      stdout.writeln(v);
      violations++;
    }
  }

  if (violations == 0) {
    stdout.writeln(
        'nullable_field_lint: ${files.length} file(s) clean.');
    exit(0);
  }
  stdout.writeln('nullable_field_lint: $violations problem(s).');
  exit(1);
}

class _NullableFieldVisitor extends RecursiveAstVisitor<void> {
  final String path;
  final dynamic lineInfo;
  final problems = <String>[];

  /// Nullable, non-final instance fields declared in this file. These can
  /// never be type-promoted, so any unguarded access is an error.
  final _nullableFields = <String>{};

  _NullableFieldVisitor(this.path, this.lineInfo);

  @override
  void visitClassDeclaration(ClassDeclaration node) {
    _nullableFields.clear();
    for (final m in node.members) {
      if (m is! FieldDeclaration) continue;
      if (m.isStatic) continue;
      // `final` fields CAN be promoted in modern Dart, so exclude them to
      // keep this check free of false positives.
      if (m.fields.isFinal || m.fields.isConst) continue;
      final type = m.fields.type;
      if (type == null) continue;
      if (type.question == null) continue; // not nullable
      for (final v in m.fields.variables) {
        _nullableFields.add(v.name.lexeme);
      }
    }
    super.visitClassDeclaration(node);
  }

  void _report(int offset, String name, String what) {
    final loc = lineInfo.getLocation(offset);
    problems.add('$path:${loc.lineNumber}:${loc.columnNumber}: '
        'ERROR nullable field "$name" used with $what without '
        '`?.`, `!` or `?? default` — this will not compile.');
  }

  @override
  void visitMethodInvocation(MethodInvocation node) {
    final target = node.target;
    if (target != null && node.operator?.lexeme == '.') {
      final name = _plainFieldName(target);
      if (name != null && _nullableFields.contains(name)) {
        _report(node.offset, name, 'method .${node.methodName.name}()');
      }
    }
    super.visitMethodInvocation(node);
  }

  /// `_field.prop` on a bare identifier parses as a PrefixedIdentifier,
  /// not a PropertyAccess — both shapes must be checked or the most
  /// common form (a plain property read) slips through.
  @override
  void visitPrefixedIdentifier(PrefixedIdentifier node) {
    if (node.period.lexeme == '.') {
      final name = node.prefix.name;
      if (_nullableFields.contains(name)) {
        _report(node.offset, name, 'property .${node.identifier.name}');
      }
    }
    super.visitPrefixedIdentifier(node);
  }

  @override
  void visitPropertyAccess(PropertyAccess node) {
    if (node.operator.lexeme == '.') {
      final name = _plainFieldName(node.target);
      if (name != null && _nullableFields.contains(name)) {
        _report(node.offset, name, 'property .${node.propertyName.name}');
      }
    }
    super.visitPropertyAccess(node);
  }

  /// Returns the field name when [e] is a bare reference to it, possibly
  /// wrapped in parentheses — `_x` or `(_x)`. Anything else (a `??`
  /// expression, a `!`, a call result) is already guarded or unrelated.
  String? _plainFieldName(Expression? e) {
    var cur = e;
    while (cur is ParenthesizedExpression) {
      cur = cur.expression;
    }
    if (cur is SimpleIdentifier) return cur.name;
    return null;
  }
}
