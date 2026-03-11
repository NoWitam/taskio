import type { VariablePrimitive } from '../types/editor';

const VARIABLE_TYPE_META: Record<VariablePrimitive, { icon: string; label: string }> = {
  text: { icon: 'signature', label: 'Tekst' },
  number: { icon: 'hash', label: 'Liczba' },
  boolean: { icon: 'check-circle', label: 'Warunek' },
};

export function getVariableIconName(type: VariablePrimitive) {
  return VARIABLE_TYPE_META[type].icon;
}

export function getVariableIconLabel(type: VariablePrimitive) {
  return VARIABLE_TYPE_META[type].label;
}

export function getVariableIconMeta(type: VariablePrimitive) {
  return VARIABLE_TYPE_META[type];
}
