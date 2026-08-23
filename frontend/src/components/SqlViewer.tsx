// SqlViewer.tsx — SQL 预览（行号栏 + 语法高亮；>5000 行窗口化渲染防卡顿）
import { useEffect, useMemo, useRef, useState, useCallback } from 'react';
import { splitSqlLines, tokenizeLine } from '../lib/sql-highlight';

const LINE_HEIGHT = 19;
const OVERSCAN = 24;

interface Props {
  sql: string;
  maxHeight?: number;
}

export default function SqlViewer({ sql, maxHeight = 560 }: Props) {
  const lines = useMemo(() => splitSqlLines(sql), [sql]);
  const [scrollTop, setScrollTop] = useState(0);
  const [height, setHeight] = useState(480);
  const containerRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    const el = containerRef.current;
    if (!el) return;
    const update = (): void => setHeight(el.clientHeight);
    update();
    const ro = new ResizeObserver(update);
    ro.observe(el);
    return () => ro.disconnect();
  }, []);

  const onScroll = useCallback(() => {
    if (containerRef.current) setScrollTop(containerRef.current.scrollTop);
  }, []);

  const start = Math.max(0, Math.floor(scrollTop / LINE_HEIGHT) - OVERSCAN);
  const end = Math.min(lines.length, Math.ceil((scrollTop + height) / LINE_HEIGHT) + OVERSCAN);
  const visible: number[] = [];
  for (let i = start; i < end; i += 1) visible.push(i);

  return (
    <div
      className="sql-scroll"
      ref={containerRef}
      onScroll={onScroll}
      style={{ maxHeight }}
      data-line-count={lines.length}
    >
      <div style={{ height: Math.max(lines.length * LINE_HEIGHT, height), position: 'relative' }}>
        <div style={{ transform: `translateY(${start * LINE_HEIGHT}px)` }}>
          {visible.map((i) => (
            <div key={i} className="sql-line" style={{ height: LINE_HEIGHT }}>
              <span className="sql-line-no">{i + 1}</span>
              <span className="sql-line-code">
                {tokenizeLine(lines[i]).map((tok, j) => (
                  <span key={j} className={tok.cls}>
                    {tok.text}
                  </span>
                ))}
              </span>
            </div>
          ))}
        </div>
      </div>
    </div>
  );
}
