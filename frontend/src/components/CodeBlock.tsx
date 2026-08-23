// CodeBlock.tsx — 代码块（--ff-code + 一键复制；用于 GRANT / my.cnf / SQL 片段）
import CopyButton from './CopyButton';

interface Props {
  lines: string[];
  title?: string;
}

export default function CodeBlock({ lines, title }: Props) {
  return (
    <div className="code-block">
      {title ? (
        <div className="code-block-head">
          <span>{title}</span>
          <CopyButton text={lines.join('\n')} label="复制" />
        </div>
      ) : null}
      <code>{lines.join('\n')}</code>
    </div>
  );
}
