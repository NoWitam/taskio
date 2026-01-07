type ClassValue = string | false | null | undefined | { [key: string]: boolean };

export function cn(...parts: Array<ClassValue>) 
{
  const classes: string[] = [];
  
  for (const part of parts) {
    if (!part) continue;
    
    if (typeof part === 'string') {
      classes.push(part);
    } else if (typeof part === 'object') {
      // Handle object with conditional classes: { 'class-name': condition }
      for (const [className, condition] of Object.entries(part)) {
        if (condition) {
          classes.push(className);
        }
      }
    }
  }
  
  return classes.join(" ");
}